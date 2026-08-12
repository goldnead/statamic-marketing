<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Field, Input, Select, Textarea,
    ConfirmationModal, Text, CommandPaletteItem,
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
    'previewUrl',      // GET, rendered HTML (edit only)
    'showUrl',         // report page (edit only)
    'lists',           // [{ value, label }]
    'segments',        // [{ value, label, members_count }] — empty if LeadHub lacks segments
    'templates',       // [{ value, label }]
    'mailClasses',     // [{ value, label }] — marketing/transactional/digest/reminder
    'frequencyCap',    // { enabled, max, window_hours }
    'editable',        // bool (edit only)
    'canSend',         // bool
]);

const isCreating = computed(() => ! props.updateUrl);
const isEditable = computed(() => isCreating.value || props.editable);

const name = ref(props.campaign?.name || '');
const handle = ref(props.campaign?.handle || '');
const subject = ref(props.campaign?.subject || '');
// Empty means "not an A/B test". Filling it in is the only way to start one.
const variantSubject = ref(props.campaign?.variant_subject || '');
const preheader = ref(props.campaign?.preheader || '');
const list = ref(props.campaign?.list || '');
const segment = ref(props.campaign?.segment || '');
const template = ref(props.campaign?.template || '');
const fromName = ref(props.campaign?.from_name || '');
const fromEmail = ref(props.campaign?.from_email || '');
const replyTo = ref(props.campaign?.reply_to || '');
const content = ref(props.campaign?.content || '');
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

const templateOptions = computed(() => [
    { value: '', label: __('Default (built-in)') },
    ...(props.templates || []),
]);

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
        preheader: preheader.value || null,
        list: list.value || null,
        segment: segment.value || null,
        template: template.value || null,
        from_name: fromName.value || null,
        from_email: fromEmail.value || null,
        reply_to: replyTo.value || null,
        content: content.value || null,
        mail_class: mailClass.value || null,
    };
}

// Validation failures used to be invisible here: the request came back with
// errors, nothing was saved, and the screen looked exactly as before — so a
// rejected campaign handle read as a dead Save button.
const formErrors = ref({});

// Every key below is rendered next to the field it belongs to. A key that is
// not in this list has no field to sit at — it goes into the summary above the
// form instead, otherwise it would be invisible again.
const fieldKeys = [
    'name', 'handle', 'subject', 'variant_subject', 'preheader', 'content',
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

                                <Field :label="__('Preheader')" :error="formErrors.preheader">
                                    <Input v-model="preheader" :placeholder="__('Preview text shown after the subject in most inboxes')" />
                                </Field>
                            </div>
                        </Card>
                    </Panel>

                    <Panel :heading="__('Content')">
                        <Card>
                            <Field :error="formErrors.content">
                                <Textarea
                                    v-model="content"
                                    rows="18"
                                    class="font-mono text-sm"
                                    :placeholder="__('Write your email content...')"
                                />
                            </Field>
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
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('The preview reflects the last saved version. Save your changes first.') }}
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
                            <iframe
                                v-if="showPreview"
                                :src="previewUrl"
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

                                <Field :label="__('Template')" :error="formErrors.template">
                                    <Select v-model="template" :options="templateOptions" />
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
                                <Field :label="__('From name')" :error="formErrors.from_name">
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
                                    <Field :label="__('Schedule for later')" :error="formErrors.scheduled_at">
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
