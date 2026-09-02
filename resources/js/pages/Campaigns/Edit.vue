<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Field, Input, Select, Textarea,
    ConfirmationModal, Text, CommandPaletteItem, ToggleGroup, ToggleItem,
    Alert, PublishContainer, PublishFieldsProvider, PublishFields,
} from '@statamic/cms/ui';

const props = defineProps([
    'campaign',        // { handle, name, subject, variant_subject, preheader, from_name, from_email, reply_to,
                       //   list, template, content, status, scheduled_at, sent_at } | null on create
    'storeUrl',        // POST endpoint (create only)
    'updateUrl',       // PATCH endpoint (edit only)
    'deleteUrl',       // DELETE endpoint (edit only)
    'sendUrl',         // POST endpoint (edit only)
    'scheduleUrl',     // POST endpoint (edit only)
    'unscheduleUrl',   // POST endpoint (edit only)
    'testUrl',         // POST endpoint (edit only)
    'previewUrl',      // GET, rendered HTML of the SAVED campaign (edit only)
    'livePreviewUrl',  // POST, renders what is being typed
    'showUrl',         // report page (edit only)
    'lists',           // [{ value, label }]
    'segments',        // [{ value, label, members_count }] — empty if LeadHub lacks segments
    // Envelopes: the HTML a campaign's own text is placed into. Each carries
    // `has_content_hole`, so the editor can say before anything is sent that a
    // layout would swallow the text.
    'layouts',         // [{ value, label, has_content_hole }]
    // Finished mails — subject and text of their own — that a campaign can send
    // instead of writing anything. A separate list because they are a different
    // kind of thing; one select for both is what made this screen confusing.
    'readyMades',      // [{ value, label }]
    // The publish form for the campaign text: { blueprint, values, meta }.
    'contentField',
    'mailClasses',     // [{ value, label }] — marketing/transactional/digest/reminder
    'frequencyCap',    // { enabled, max, window_hours }
    'editable',        // bool (edit only)
    'canSend',         // bool
    'timezone',        // string — the zone a scheduled time is read in (edit only)
]);

const isCreating = computed(() => ! props.updateUrl);
const isEditable = computed(() => isCreating.value || props.editable);

const name = ref(props.campaign?.name || '');
const handle = ref(props.campaign?.handle || '');
const subject = ref(props.campaign?.subject || '');
// Empty means "not an A/B test". Filling it in is the only way to start one.
const variantSubject = ref(props.campaign?.variant_subject || '');
// 0 = split the whole audience (today's behaviour). Stored and validated now;
// the winner send that would act on 10–50 is Phase 2.
const abShare = ref(props.campaign?.ab_share ?? 0);
const preheader = ref(props.campaign?.preheader || '');
const list = ref(props.campaign?.list || '');
const segment = ref(props.campaign?.segment || '');
const template = ref(props.campaign?.template || '');
const fromName = ref(props.campaign?.from_name || '');
const fromEmail = ref(props.campaign?.from_email || '');
const replyTo = ref(props.campaign?.reply_to || '');
// The publish form's own value bag. Bard hands back a ProseMirror document, and
// the server turns it into the HTML string the column holds — the conversion is
// Statamic's, in both directions, so nothing here has to know about either shape.
const contentValues = ref({ ...(props.contentField?.values ?? {}) });

// One tab, one section, one field — but read out of the blueprint rather than
// assumed, so adding a second field later is a change in one place.
const contentFields = computed(
    () => props.contentField?.blueprint?.tabs?.[0]?.sections?.[0]?.fields ?? [],
);

// Defaults to marketing, which is the only value that is subject to the cap.
// Opting a campaign out is an act; leaving this alone never is.
const mailClass = ref(props.campaign?.mail_class || 'marketing');

const capDays = computed(() => Math.round((props.frequencyCap?.window_hours ?? 168) / 24));

const capNote = computed(() => {
    if (! props.frequencyCap?.enabled) {
        return __('No frequency cap is configured, so every class is delivered immediately. The class is still stored and still applies the moment a cap is switched on.');
    }

    if (mailClass.value === 'marketing') {
        return __('Marketing is the only class the cap holds back. Recipients who have already reached the limit get this campaign later, or not at all if it stays over the limit.');
    }

    return __('This class is exempt: it is neither counted against the limit nor held back by it.');
});

// Kept in script so Vue's template compiler never sees the Antlers braces.
const antlersHint = ['first_name', 'name', 'email', 'unsubscribe_url']
    .map((variable) => `{{ ${variable} }}`)
    .join(', ');

const testEmail = ref('');
const scheduledAt = ref('');
const showPreview = ref(false);
const showSendConfirm = ref(false);
const showDeleteConfirm = ref(false);

const listOptions = computed(() => [
    { value: '', label: __('Choose a list...') },
    ...(props.lists || []),
]);

// Which of the two things this campaign sends. Not stored: derived from the
// handle, because a handle that names a finished mail can only mean one of them.
// Keeping it out of the record is what makes this a pure UI change — the send
// path, the API and every existing campaign are untouched.
const mode = ref(
    (props.readyMades || []).some((option) => option.value === (props.campaign?.template || ''))
        ? 'ready_made'
        : 'own_text',
);

// No empty option: the Select shows its placeholder for an empty value and the
// option would never be seen. The meaning goes on the placeholder instead, where
// it is read — "no layout chosen" and "the built-in layout" are the same thing,
// and the screen has to say which.
const layoutOptions = computed(() => props.layouts || []);

const readyMadeOptions = computed(() => props.readyMades || []);

function switchMode(next) {
    if (! next || next === mode.value) return;

    // The handle means different things in the two modes, so carrying it across
    // would silently turn a layout into a mail or the other way round.
    template.value = '';
    mode.value = next;
}

/**
 * The layout that is chosen has no hole for the text.
 *
 * The failure this warns about is silent and total: the campaign is written,
 * the send succeeds, and every recipient gets the layout with none of the text
 * in it.
 */
const layoutSwallowsContent = computed(() => {
    if (mode.value !== 'own_text' || ! template.value) return false;

    const chosen = (props.layouts || []).find((option) => option.value === template.value);

    return chosen ? chosen.has_content_hole === false : false;
});

const hasSegments = computed(() => (props.segments || []).length > 0);

const segmentOptions = computed(() => [
    { value: '', label: __('Entire list (no segment)') },
    ...(props.segments || []).map((s) => ({
        value: s.value,
        label: `${s.label} (${s.members_count})`,
    })),
]);

const selectedSegmentCount = computed(() => {
    const match = (props.segments || []).find((s) => s.value === segment.value);
    return match ? match.members_count : null;
});

function statusColor(status) {
    return {
        draft: 'default',
        scheduled: 'purple',
        sending: 'yellow',
        sent: 'green',
    }[status] || 'default';
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function payload() {
    return {
        name: name.value,
        ...(isCreating.value ? { handle: handle.value || null } : {}),
        subject: subject.value || null,
        variant_subject: variantSubject.value || null,
        ab_share: variantSubject.value.trim() ? (Number(abShare.value) || 0) : 0,
        preheader: preheader.value || null,
        list: list.value || null,
        segment: segment.value || null,
        template: template.value || null,
        from_name: fromName.value || null,
        from_email: fromEmail.value || null,
        reply_to: replyTo.value || null,
        content: contentValues.value.content ?? null,
        mail_class: mailClass.value || null,
    };
}

// Validation failures used to be invisible here: the request came back with
// errors, nothing was saved, and the screen looked exactly as before — so a
// rejected campaign handle read as a dead Save button.
const formErrors = ref({});

// The server's rejection of the text, keyed the way the publish form expects it.
// Declared next to `formErrors` and not next to the value it belongs to, because
// the one it belongs to is read before this file declares the errors.
const contentErrors = computed(() =>
    formErrors.value.content ? { content: [formErrors.value.content] } : {}
);

// Every key below is rendered next to the field it belongs to. A key that is
// not in this list has no field to sit at — it goes into the summary above the
// form instead, otherwise it would be invisible again.
const fieldKeys = [
    'name', 'handle', 'subject', 'variant_subject', 'ab_share', 'preheader', 'content',
    'list', 'segment', 'template', 'mail_class',
    'from_name', 'from_email', 'reply_to',
    'email', 'scheduled_at',
];

// Which of those keys actually has a field on screen right now. The handle
// input is only rendered while creating (`v-if="isCreating"`), so on an update
// a rejected handle has nowhere to sit — and being on the list above would
// filter it out of the summary as "already shown at its field". It would then
// be shown nowhere at all, which is the exact failure 1.5.3 set out to end.
const keysWithAVisibleField = computed(() =>
    fieldKeys.filter((key) => key !== 'handle' || isCreating.value)
);

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! keysWithAVisibleField.value.includes(key))
        .map(([, message]) => message)
);

function save() {
    if (! name.value.trim()) return;

    const options = {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    };

    if (isCreating.value) {
        router.post(props.storeUrl, payload(), options);
    } else {
        router.patch(props.updateUrl, payload(), options);
    }
}

function sendTest() {
    if (! testEmail.value.trim()) return;
    router.post(props.testUrl, { email: testEmail.value }, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; testEmail.value = ''; },
    });
}

function schedule() {
    if (! scheduledAt.value) return;
    router.post(props.scheduleUrl, { scheduled_at: scheduledAt.value }, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; scheduledAt.value = ''; },
    });
}

function unschedule() {
    router.post(props.unscheduleUrl, {}, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    });
}

function sendNow() {
    showSendConfirm.value = false;
    router.post(props.sendUrl, {}, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    });
}

function destroy() {
    router.delete(props.deleteUrl, {
        onError: (errors) => { formErrors.value = errors || {}; },
    });
}

// ---------- Live preview ----------
//
// Bis hierher zeigte die Vorschau die gespeicherte Fassung, und die Oberflaeche
// sagte es auch dazu. Damit war sie drei Schritte vom Bearbeiteten entfernt:
// schreiben, speichern, ansehen. Die Vorlagen-Seite dieses Addons kann es
// laengst besser; das hier ist dasselbe Muster.
//
// Gerendert wird serverseitig durch denselben CampaignRenderer, den der echte
// Versand nimmt. Ein zweiter Renderer auf dieser Seite waere ein zweites Ding,
// das man in Gleichschritt halten muss, und die erste Abweichung faende jemand
// in seinem Posteingang.

const previewHtml = ref('');
const previewError = ref(null);
const previewStale = ref(false);

let previewTimer = null;
let previewRequest = 0;

async function refreshPreview() {
    if (! props.livePreviewUrl || ! showPreview.value) return;

    // Jede Antwort traegt die Nummer der Anfrage, die sie erbeten hat. Tippen
    // schickt mehrere gleichzeitig los, und ohne das kann die langsame Antwort
    // auf einen aelteren Tastendruck zuletzt ankommen und eine Vorschau malen,
    // die so nicht mehr auf dem Schirm steht.
    const mine = ++previewRequest;

    try {
        const response = await fetch(props.livePreviewUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': window.Statamic?.$config?.get('csrfToken') ?? '',
            },
            body: JSON.stringify({
                handle: handle.value,
                name: name.value,
                subject: subject.value,
                preheader: preheader.value,
                content: contentValues.value.content ?? null,
                list_handle: list.value,
                template_handle: template.value,
            }),
        });

        if (! response.ok) throw new Error(String(response.status));

        const { data } = await response.json();

        if (mine !== previewRequest) return;

        previewError.value = data.error;
        previewStale.value = false;

        // Ein Parse-Fehler laesst das letzte Bild stehen, statt zu leeren:
        // halb getippte Antlers ist der Normalzustand einer Kampagne, an der
        // jemand schreibt.
        if (! data.error) previewHtml.value = data.html;
    } catch (e) {
        if (mine !== previewRequest) return;
        previewStale.value = true;
    }
}

function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(refreshPreview, 500);
}

watch([contentValues, subject, preheader, template], schedulePreview, { deep: true });
watch(showPreview, (open) => { if (open) refreshPreview(); });
onBeforeUnmount(() => clearTimeout(previewTimer));

</script>

<template>
    <Head :title="[isCreating ? __('Create campaign') : campaign.name, __('Campaigns'), __('Marketing')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="isCreating ? __('Create campaign') : name" icon="mail">
            <Badge
                v-if="campaign"
                :color="statusColor(campaign.status)"
                :text="campaign.status"
            />
            <Button
                v-if="deleteUrl"
                :text="__('Delete')"
                variant="danger"
                @click="showDeleteConfirm = true"
            />
            <Button
                v-if="isEditable"
                :text="__('Save')"
                variant="primary"
                :disabled="!name.trim()"
                @click="save"
            />
        </Header>

        <!-- Locked campaigns can no longer be edited -->
        <Panel v-if="!isEditable" class="mb-4">
            <div class="p-4">
                <Text size="sm">
                {{ __('This campaign has been sent or is currently sending and can no longer be edited.') }}
                </Text>
                <Link :href="showUrl" class="font-medium hover:underline">
                    {{ __('View the report') }} →
                </Link>
            </div>
        </Panel>

        <Panel v-if="generalErrors.length" class="mb-4" data-marketing-form-errors>
            <div class="p-4 text-sm text-red-600 dark:text-red-400">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
            </div>
        </Panel>

        <template v-if="isEditable">
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Main column -->
                <div class="lg:col-span-2 space-y-4">
                    <Panel :heading="__('Campaign')">
                        <Card>
                            <div class="space-y-4">
                                <Field :label="__('Name')" :error="formErrors.name">
                                    <Input v-model="name" :placeholder="__('e.g. March newsletter')" />
                                </Field>

                                <Field v-if="isCreating" :label="__('Handle')" :error="formErrors.handle">
                                    <Input v-model="handle" placeholder="march_newsletter" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Lowercase letters, numbers and underscores (snake_case). Leave empty to generate from the name.') }}
                                    </p>
                                </Field>

                                <Field :label="__('Subject')" :error="formErrors.subject">
                                    <Input v-model="subject" :placeholder="__('The email subject line')" />
                                </Field>

                                <Field :label="__('Subject variant B (A/B test)')" :error="formErrors.variant_subject">
                                    <Input v-model="variantSubject" :placeholder="__('Leave empty for no A/B test')" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Splits the audience evenly and permanently: each recipient is assigned to A or B once, when the campaign starts sending, and keeps that variant. The report breaks every figure down per variant but does not pick a winner.') }}
                                    </p>
                                </Field>

                                <Field
                                    v-if="variantSubject.trim()"
                                    :label="__('marketing::campaigns.ab_share')"
                                    :instructions="__('marketing::campaigns.ab_share_help')"
                                    :error="formErrors.ab_share"
                                >
                                    <div class="w-32">
                                        <Input v-model="abShare" type="number" min="0" max="50" />
                                    </div>
                                </Field>

                                <Field :label="__('Preheader')" :error="formErrors.preheader">
                                    <Input v-model="preheader" :placeholder="__('Preview text shown after the subject in most inboxes')" />
                                </Field>
                            </div>
                        </Card>
                    </Panel>

                    <Panel :heading="__('Content')">
                        <Card>
                            <!-- Written in the same editor as an email template,
                                 because it is the same kind of writing done by
                                 the same person. `save_html` is on, so what
                                 leaves this field is the HTML string the column
                                 has always held — see Support\CampaignContentField. -->
                            <Alert v-if="mode === 'ready_made'" variant="default" class="mb-3" data-marketing-campaign-content-unused>
                                {{ __('marketing::campaigns.content_unused') }}
                            </Alert>

                            <PublishContainer
                                v-if="contentField"
                                name="campaign-content"
                                :blueprint="contentField.blueprint"
                                :meta="contentField.meta"
                                :model-value="contentValues"
                                :errors="contentErrors"
                                @update:model-value="contentValues = $event"
                            >
                                <!-- Provider then renderer: `PublishFields`
                                     draws whatever the surrounding provider put
                                     in context, and has no props of its own. -->
                                <PublishFieldsProvider :fields="contentFields">
                                    <PublishFields />
                                </PublishFieldsProvider>
                            </PublishContainer>

                            <!-- The server's rejection of the text, at the
                                 field it belongs to. The publish container gets
                                 the same message for its own field state; this
                                 line is what a reader actually sees. -->
                            <p
                                v-if="formErrors.content"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                                data-marketing-campaign-content-error
                            >{{ formErrors.content }}</p>

                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Antlers variables are available:') }}
                                <code v-text="antlersHint"></code>
                            </p>
                        </Card>
                    </Panel>

                    <!-- Preview -->
                    <Panel v-if="previewUrl" :heading="__('Preview')">
                        <Card>
                            <div class="flex items-center gap-2">
                                <Button
                                    :text="showPreview ? __('Hide preview') : __('Show preview')"
                                    variant="default"
                                    @click="showPreview = !showPreview"
                                />
                                <a
                                    :href="previewUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-xs text-gray-500 hover:underline"
                                >
                                    {{ __('Open in new tab') }} ↗
                                </a>
                            </div>
                            <p v-if="previewError" class="mt-2 text-xs text-red-600 dark:text-red-400">
                                {{ previewError }}
                            </p>
                            <p v-else-if="previewStale" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('The preview could not be refreshed. What you see is the last render.') }}
                            </p>
                            <p v-else class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('The preview updates as you type.') }}
                            </p>
                            <!--
                                The frame shows HTML a Control Panel user wrote,
                                from a Control Panel route. `sandbox` with no
                                tokens puts it in a unique opaque origin with
                                scripts off; adding `allow-scripts` or
                                `allow-same-origin` back would hand an editor
                                who can write a template the session of every
                                super user who previews it. The response carries
                                the matching Content-Security-Policy. Held by
                                tests/js/preview-sandbox.test.js.
                            -->
                            <!--
                                `srcdoc` statt `src`, weil die Vorschau jetzt
                                aus einer POST-Antwort kommt. Am `sandbox=""`
                                aendert das nichts: ein srcdoc-Rahmen ohne
                                Tokens ist derselbe undurchsichtige Ursprung
                                ohne Skripte. Gehalten von
                                tests/js/preview-sandbox.test.js.
                            -->
                            <iframe
                                v-if="showPreview"
                                :srcdoc="previewHtml"
                                :title="__('Email preview')"
                                sandbox=""
                                class="mt-3 w-full h-[600px] rounded border border-content-border bg-content-bg"
                            ></iframe>
                        </Card>
                    </Panel>
                </div>

                <!-- Sidebar -->
                <aside class="space-y-4">
                    <Panel :heading="__('Recipients')">
                        <Card>
                            <div class="space-y-4">
                                <Field :label="__('List')" :error="formErrors.list">
                                    <Select v-model="list" :options="listOptions" />
                                </Field>

                                <Field v-if="hasSegments" :label="__('Segment')" :error="formErrors.segment">
                                    <Select v-model="segment" :options="segmentOptions" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <template v-if="segment && selectedSegmentCount !== null">
                                            {{ __('Narrows to subscribers who are also in this segment.') }}
                                            <Badge color="blue" :text="String(selectedSegmentCount)" />
                                            {{ __('contacts currently match.') }}
                                        </template>
                                        <template v-else>
                                            {{ __('Optionally narrow the audience to a LeadHub segment. Consent still comes from the list.') }}
                                        </template>
                                    </p>
                                </Field>

                                <!-- Two things used to share one select called
                                     "Template": the envelope a campaign is sent
                                     in, and a finished mail with its own
                                     subject and text. Choosing the second made
                                     it the campaign's layout, and since a
                                     finished mail has no hole for the text, the
                                     campaign's own words were dropped in
                                     silence. They are two questions, so they
                                     are two controls. -->
                                <Field :label="__('marketing::campaigns.sends_label')">
                                    <ToggleGroup
                                        :model-value="mode"
                                        size="sm"
                                        :aria-label="__('marketing::campaigns.sends_label')"
                                        data-marketing-campaign-mode
                                        @update:model-value="switchMode"
                                    >
                                        <ToggleItem value="own_text" :label="__('marketing::campaigns.mode_own_text')" />
                                        <ToggleItem value="ready_made" :label="__('marketing::campaigns.mode_ready_made')" />
                                    </ToggleGroup>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ mode === 'own_text'
                                            ? __('marketing::campaigns.mode_own_text_help')
                                            : __('marketing::campaigns.mode_ready_made_help') }}
                                    </p>
                                </Field>

                                <Field
                                    v-if="mode === 'own_text'"
                                    :label="__('marketing::campaigns.layout_label')"
                                    :error="formErrors.template"
                                >
                                    <Select
                                        v-model="template"
                                        :options="layoutOptions"
                                        :placeholder="__('marketing::campaigns.layout_default')"
                                        data-marketing-campaign-layout
                                    />
                                    <p
                                        v-if="layoutSwallowsContent"
                                        class="mt-1 text-sm text-red-600 dark:text-red-400"
                                        data-marketing-campaign-layout-warning
                                    >{{ __('marketing::campaigns.layout_no_hole') }}</p>
                                </Field>

                                <Field
                                    v-else
                                    :label="__('marketing::campaigns.ready_made_label')"
                                    :error="formErrors.template"
                                >
                                    <Select
                                        v-model="template"
                                        :options="readyMadeOptions"
                                        :placeholder="__('marketing::campaigns.choose_ready_made')"
                                        data-marketing-campaign-ready-made
                                    />
                                </Field>

                                <!-- The frequency-cap classification. Sits with
                                     the audience rather than with the sender,
                                     because what it changes is who receives
                                     this and when, not how it looks. -->
                                <Field :label="__('Classification')" :error="formErrors.mail_class">
                                    <Select v-model="mailClass" :options="mailClasses || []" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <template v-if="frequencyCap && frequencyCap.enabled">
                                            {{ __('Cap in force:') }}
                                            <Badge color="blue" :text="String(frequencyCap.max)" />
                                            {{ __('marketing mails per') }} {{ capDays }} {{ __('days') }}.
                                        </template>
                                        {{ capNote }}
                                    </p>
                                </Field>
                            </div>
                        </Card>
                    </Panel>

                    <Panel :heading="__('Sender')">
                        <Card>
                            <div class="space-y-4">
                                <!--
                                    Auch hier, und aus einem eigenen Grund: der
                                    Anzeigename wird im selben Fall verworfen wie
                                    die Adresse, aber ohne Logzeile — er hängt an
                                    der Adresse und wird nur zusammen mit ihr
                                    gesetzt. Ohne diesen Hinweis ist das die
                                    einzige Stelle des Formulars, an der ein
                                    Eingabewert spurlos verschwindet.
                                -->
                                <Field
                                    :label="__('From name')"
                                    :error="formErrors.from_name"
                                    :instructions="__('Ignored for a brand that declares its own settings.mail.from_address — the display name travels with the address, and the brand supplies both.')"
                                >
                                    <Input v-model="fromName" :placeholder="__('Defaults to the site sender')" />
                                </Field>

                                <!--
                                    The instructions are not decoration. Since
                                    statamic-marketing 2.2.0 a brand that
                                    declares settings.mail.from_address wins
                                    against this field, and a field that is
                                    silently ignored while its placeholder says
                                    "Defaults to the site sender" is a lie the
                                    editor cannot see. Same sentence as the
                                    `from` field of the send_email node in
                                    statamic-automations, because it is the same
                                    rule.
                                -->
                                <Field
                                    :label="__('From email')"
                                    :error="formErrors.from_email"
                                    :instructions="__('Ignored for a brand that declares its own settings.mail.from_address — the sending address has to match the relay account the brand sends through, and only the brand row knows which addresses that account owns. Reply-to is unaffected.')"
                                >
                                    <Input v-model="fromEmail" type="email" :placeholder="__('Defaults to the site sender')" />
                                </Field>

                                <Field :label="__('Reply-to')" :error="formErrors.reply_to">
                                    <Input v-model="replyTo" type="email" :placeholder="__('Optional')" />
                                </Field>
                            </div>
                        </Card>
                    </Panel>

                    <!-- Send test -->
                    <Panel v-if="testUrl && canSend" :heading="__('Send test')">
                        <Card>
                            <div class="space-y-2">
                                <Field :label="__('Test recipient')" :error="formErrors.email">
                                    <Input v-model="testEmail" type="email" placeholder="you@example.com" />
                                </Field>
                                <Button
                                    :text="__('Send test email')"
                                    variant="default"
                                    :disabled="!testEmail.trim()"
                                    @click="sendTest"
                                />
                            </div>
                        </Card>
                    </Panel>

                    <!-- Schedule / send -->
                    <Panel v-if="updateUrl && canSend" :heading="__('Delivery')">
                        <Card>
                            <div class="space-y-4">
                                <div v-if="campaign.status === 'scheduled'" class="space-y-2">
                                    <div class="text-sm">
                                        <Badge color="purple" :text="__('Scheduled')" />
                                        <Text size="sm" class="ms-2 inline">
                                            {{ formatDate(campaign.scheduled_at) }}
                                        </Text>
                                    </div>
                                    <Button :text="__('Unschedule')" variant="default" @click="unschedule" />
                                </div>

                                <div v-else class="space-y-2">
                                    <Field
                                        :label="__('Schedule for later')"
                                        :instructions="timezone ? __('marketing::campaigns.schedule_timezone', { timezone }) : null"
                                        :error="formErrors.scheduled_at"
                                    >
                                        <Input v-model="scheduledAt" type="datetime-local" />
                                    </Field>
                                    <Button :text="__('Schedule')" variant="default" :disabled="!scheduledAt" @click="schedule" />
                                </div>

                                <div class="pt-4 border-t border-content-border">
                                    <Button
                                        :text="__('Send now')"
                                        variant="primary"
                                        class="w-full"
                                        @click="showSendConfirm = true"
                                    />
                                </div>
                            </div>
                        </Card>
                    </Panel>
                </aside>
            </div>
        </template>

        <ConfirmationModal
            :open="showSendConfirm"
            :title="__('Send campaign')"
            :body-text="__('Send this campaign to all subscribers of the selected list now? This cannot be undone.')"
            danger
            :button-text="__('Send now')"
            @cancel="showSendConfirm = false"
            @confirm="sendNow"
        />

        <ConfirmationModal
            :open="showDeleteConfirm"
            :title="__('Delete campaign')"
            :body-text="__('Delete this campaign? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
