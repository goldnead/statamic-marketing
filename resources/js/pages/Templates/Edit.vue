<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Dropdown, DropdownMenu, DropdownItem,
    Field, Input, CodeEditor, Alert,
    ToggleGroup, ToggleItem, ConfirmationModal,
    PublishContainer, PublishFieldsProvider, PublishFields,
} from '@statamic/cms/ui';

const props = defineProps([
    'template',      // { handle, name, type, html, blocks } | null on create
    'storeUrl',      // POST endpoint (create only)
    'updateUrl',     // PATCH endpoint (edit only)
    'deleteUrl',     // DELETE endpoint (edit only)
    'starterHtml',   // string — prefill on create
    'previewUrl',    // POST endpoint that renders the layout being typed
    // Every placeholder a send fills in, as the dotted names a layout prints
    // them by. Comes from the renderer itself, so the list cannot drift from
    // what an actual campaign provides.
    'availableVariables',
    // Whether the kind of layout is still open. True only while creating —
    // afterwards it is fixed, and the screen says so rather than hiding it.
    'canChooseType',
    // The publish form for the building blocks: { blueprint, values, meta }.
    // Null for an HTML layout, which has no blocks and never gets any.
    'blocksField',
]);

const isCreating = computed(() => ! props.updateUrl);

const name = ref(props.template?.name || '');
const handle = ref(props.template?.handle || '');
const html = ref(props.template?.html ?? props.starterHtml ?? '');

// ---------- The two kinds of layout ----------
//
// Blocks are a second *input*, not a second output. Whatever is built here
// leaves as one HTML string in the same column a hand-written layout fills,
// so the renderer, the send, the snapshot and the archive never learn that
// blocks exist. The choice is offered once, on create, and is then fixed:
// there is no way back from HTML to blocks that is not guessing, and a
// converter that guesses would be wrong exactly once, on somebody's layout.

// A new layout opens as a building set, not as a wall of HTML. That is the
// whole complaint this answers: hand-written mail HTML is a thing Adrian can
// do, and precisely the wrong first step for everybody who buys the addon.
// Anything that already exists keeps the kind it was written in, and anything
// that arrives without one is read as `html` — the shape every layout had
// before this column existed.
const type = ref(props.template?.type ?? (props.canChooseType ? 'blocks' : 'html'));
const isBlockLayout = computed(() => type.value === 'blocks');

const blockValues = ref({ ...(props.blocksField?.values ?? {}) });

// `meta` is two-way here and not a plain prop. The replicator mutates its own
// metadata whenever a row is added or duplicated — that is where the new row's
// field metadata is registered — and the publish container hands the change
// back through `update:meta`. Binding it one-way would drop every row added
// after load on the next render.
const blockMeta = ref({ ...(props.blocksField?.meta ?? {}) });

// One tab, one section, one field — read out of the blueprint rather than
// named here, the same way the campaign editor does it.
const blockFields = computed(
    () => props.blocksField?.blueprint?.tabs?.[0]?.sections?.[0]?.fields ?? [],
);

const showDeleteConfirm = ref(false);

// Kept in script so Vue's template compiler never sees the Antlers braces —
// a literal `}}` in the template closes the mustache the compiler is reading.
const contentTag = '{{ content }}';

/** One placeholder as it is written in a layout. Built here for the same reason. */
function placeholder(name) {
    return '{{ ' + name + ' }}';
}
const unsubscribeTag = '{{ unsubscribe_url }}';

// A rejected template used to look like a dead Save button: the response came
// back with errors, nothing was written, and the screen did not change. Errors
// now land on the field they belong to.
const formErrors = ref({});

// The container keeps its own field state and wants the server's rejections in
// the shape it understands. Passed for the same reason the campaign editor
// passes it: the moment a rule lands on a single block field, its message has
// somewhere to sit instead of nowhere.
const blockErrors = computed(() =>
    formErrors.value.blocks ? { blocks: [formErrors.value.blocks] } : {}
);

// Keys rendered next to their own field. Anything else has no field to sit at
// and goes into the summary above the form, or it would be invisible again.
const fieldKeys = ['name', 'handle', 'html', 'blocks', 'type'];

// Which of those keys actually has a field on screen right now. The handle
// input is only rendered while creating (`v-if="isCreating"`), so on an update
// a rejected handle has nowhere to sit — and being on the list above would
// filter it out of the summary as "already shown at its field". It would then
// be shown nowhere at all, which is the exact failure 1.5.3 set out to end.
const keysWithAVisibleField = computed(() =>
    fieldKeys.filter((key) => {
        if (key === 'handle' || key === 'type') return isCreating.value;
        // Only one of the two editors is on screen at a time, so only one of
        // the two keys has anywhere to sit. The other would be filtered out of
        // the summary as "already shown at its field" and then shown nowhere.
        if (key === 'html') return ! isBlockLayout.value;
        if (key === 'blocks') return isBlockLayout.value;

        return true;
    })
);

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! keysWithAVisibleField.value.includes(key))
        .map(([, message]) => message)
);

// ---------- Live preview ----------
//
// A layout is the envelope, and until now the only way to see one was to save
// it, write a campaign and send yourself a test — three steps away from the
// thing being edited. The preview renders through the same Antlers parser the
// real send uses (Services\TemplatePreview), so what shows here is what goes
// out; a second renderer on this side would be a second thing to keep in step,
// and the first divergence would appear in somebody's inbox.

const previewHtml = ref('');
const previewError = ref(null);
const previewStale = ref(false);
const findings = ref([]);
const previewWidth = ref('desktop');

// Light or dark, and the switch is the editor's own — never the Control
// Panel's theme. What is being asked here is not "is my CP dark" but "what
// does a phone set to dark do with this mail": Apple Mail, Gmail and Outlook
// put it on a dark page and let `prefers-color-scheme: dark` in the layout
// take effect. Until now the only way to find out was to send yourself a test
// and change your phone's settings. See resources/css/cp.css for why this is
// an explicit choice and not a mirror of the CP theme.
const previewScheme = ref('light');

let previewTimer = null;
let previewRequest = 0;

async function refreshPreview() {
    if (! props.previewUrl) return;

    // Every response carries the number of the request that asked for it. Typing
    // fires several in flight at once, and without this the slow answer to an
    // older keystroke can land last and paint a preview of text that is no
    // longer on screen.
    const mine = ++previewRequest;

    // `fetch` and the Control Panel's own CSRF token, rather than importing
    // axios. This addon has no axios dependency and one call does not earn it —
    // importing it would ship the library a second time in a bundle that is
    // already loaded next to the Control Panel's own copy.
    try {
        const response = await fetch(props.previewUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': window.Statamic?.$config?.get('csrfToken') ?? '',
            },
            // The blocks go over as blocks, never as HTML built on this side.
            // The server runs the same translator the save runs, so what the
            // preview shows is what the column will hold — a second translator
            // in the browser would be a second truth, and the first divergence
            // between them would surface in somebody's inbox.
            body: JSON.stringify(isBlockLayout.value
                ? { type: 'blocks', blocks: blockValues.value.blocks ?? [] }
                : { type: 'html', html: html.value }),
        });

        if (! response.ok) throw new Error(String(response.status));

        const { data } = await response.json();

        if (mine !== previewRequest) return;

        previewError.value = data.error;
        findings.value = data.findings ?? [];
        previewStale.value = false;

        // A parse error keeps the last render on screen rather than blanking
        // it: half-typed Antlers is the normal state of a layout being edited,
        // and a preview that goes white on every open brace is worse than none.
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

// `deep` on the blocks: what changes is a field inside a row, and a shallow
// watch on the wrapper object never fires for that.
watch(html, schedulePreview);
watch(blockValues, schedulePreview, { deep: true });
watch(type, refreshPreview);
onMounted(refreshPreview);
onBeforeUnmount(() => clearTimeout(previewTimer));

const blockingFindings = computed(() => findings.value.filter((f) => f.level === 'error'));
const advisoryFindings = computed(() => findings.value.filter((f) => f.level !== 'error'));

// The frame the preview is rendered in.
//
// `sandbox=""` — granting nothing — and not merely "no allow-scripts". A layout
// is arbitrary HTML somebody pasted; a `<script>` in it does nothing in a mail
// client and would otherwise run here, in the Control Panel, with the editor's
// session. The empty attribute puts the document in a unique opaque origin with
// scripts off, and every token added back is a hole: `allow-scripts` turns
// execution on, and `allow-same-origin` returns the frame to the Control
// Panel's origin — together they are documented as equivalent to no sandbox at
// all. Same rule as the campaign preview; `tests/js/preview-sandbox.test.js`
// holds both to it.
const previewSandbox = '';

function save() {
    if (! name.value.trim()) return;

    // `type` travels only on create. On an update the stored kind is the
    // authority — sending it again would be a request to change something that
    // cannot be changed, and the server rejects exactly that.
    const payload = {
        name: name.value,
        ...(isCreating.value ? { handle: handle.value || null, type: type.value } : {}),
        ...(isBlockLayout.value
            ? { blocks: blockValues.value.blocks ?? [] }
            : { html: html.value || null }),
    };

    const options = {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    };

    if (isCreating.value) {
        router.post(props.storeUrl, payload, options);
    } else {
        router.patch(props.updateUrl, payload, options);
    }
}

function destroy() {
    router.delete(props.deleteUrl, {
        onError: (errors) => { formErrors.value = errors || {}; },
    });
}
</script>

<template>
    <Head :title="[isCreating ? __('marketing::templates.create') : template.name, __('marketing::templates.title'), __('Marketing')]" />

    <!-- `data-marketing-full-bleed` lifts the 1360px page cap for this screen.
         An editor with a code pane beside a live email preview is the case the
         cap was never written for; the rule and the measurement behind it are
         in resources/css/cp.css. -->
    <div class="max-w-page mx-auto" data-max-width-wrapper data-marketing-full-bleed>
        <Header :title="isCreating ? __('marketing::templates.create') : name" icon="template-theme-design-layout">
            <Dropdown v-if="deleteUrl">
                <DropdownMenu>
                    <DropdownItem
                        :text="__('Delete')"
                        icon="trash"
                        variant="destructive"
                        @click="showDeleteConfirm = true"
                    />
                </DropdownMenu>
            </Dropdown>
            <Button :text="__('Save')" variant="primary" :disabled="!name.trim()" @click="save" />
        </Header>

        <Alert v-if="generalErrors.length" variant="error" class="mb-4" data-marketing-form-errors>
            <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
        </Alert>

        <Panel :heading="__('Details')" class="mb-4">
            <Card>
                <div class="space-y-4">
                    <Field :label="__('Name')" :error="formErrors.name">
                        <Input v-model="name" :placeholder="__('e.g. Newsletter layout')" />
                    </Field>

                    <Field v-if="isCreating" :label="__('Handle')" :error="formErrors.handle">
                        <Input v-model="handle" placeholder="newsletter_layout" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Lowercase letters, numbers and underscores (snake_case). Leave empty to generate from the name.') }}
                        </p>
                    </Field>

                    <!-- The choice, and it is offered exactly once. Saying at
                         the same moment that it is final is the whole point:
                         somebody who picks "blocks" and later wants HTML has
                         to start a new layout, and finding that out after an
                         afternoon of work would be our fault, not theirs. -->
                    <Field
                        v-if="canChooseType"
                        :label="__('marketing::templates.type_choose')"
                        :error="formErrors.type"
                    >
                        <ToggleGroup
                            :model-value="type"
                            :aria-label="__('marketing::templates.type')"
                            data-marketing-template-type
                            @update:model-value="(value) => { if (value) type = value; }"
                        >
                            <ToggleItem value="blocks" :label="__('marketing::templates.type_blocks')" />
                            <ToggleItem value="html" :label="__('marketing::templates.type_html')" />
                        </ToggleGroup>

                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" data-marketing-template-type-description>
                            {{ isBlockLayout
                                ? __('marketing::templates.type_blocks_description')
                                : __('marketing::templates.type_html_description') }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-marketing-template-type-fixed>
                            {{ __('marketing::templates.type_is_fixed_hint') }}
                        </p>
                    </Field>
                </div>
            </Card>
        </Panel>

        <!-- What is wrong with the layout, above it rather than beside it: a
             layout that never prints {{ content }} sends an empty mail, and
             that has to be read before the editor scrolls past it. -->
        <Alert v-if="blockingFindings.length" variant="error" class="mb-4" data-marketing-template-findings>
            <ul class="list-inside list-disc space-y-0.5">
                <li v-for="(finding, i) in blockingFindings" :key="i">{{ finding.message }}</li>
            </ul>
        </Alert>

        <Alert v-if="advisoryFindings.length" variant="warning" class="mb-4" data-marketing-template-warnings>
            <ul class="list-inside list-disc space-y-0.5">
                <li v-for="(finding, i) in advisoryFindings" :key="i">{{ finding.message }}</li>
            </ul>
        </Alert>

        <!-- Code left, result right. Stacked below `lg`, because two columns on
             a narrow screen give neither of them enough width to be read. -->
        <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
            <!-- The building set. Statamic's own replicator, not an editor of
                 our own: reordering, collapsing, disabling, duplicating and
                 keyboard handling all come with it and all look like the rest
                 of the Control Panel. What the addon contributes is the list
                 of blocks and the translator behind them. -->
            <Panel v-if="isBlockLayout" :heading="__('marketing::templates.blocks')">
                <Card>
                    <!-- The marker sits on a plain wrapper, not on the
                         container: a Statamic component is free not to forward
                         stray attributes to its root, and a marker that only
                         exists in the test's stub is a marker that proves
                         nothing about the built page. -->
                    <div v-if="blocksField" data-marketing-template-blocks>
                        <PublishContainer
                            name="template-blocks"
                            :blueprint="blocksField.blueprint"
                            :meta="blockMeta"
                            :model-value="blockValues"
                            :errors="blockErrors"
                            @update:model-value="blockValues = $event"
                            @update:meta="blockMeta = $event"
                        >
                            <PublishFieldsProvider :fields="blockFields">
                                <PublishFields />
                            </PublishFieldsProvider>
                        </PublishContainer>
                    </div>

                    <p
                        v-if="formErrors.blocks"
                        class="mt-1 text-sm text-red-600 dark:text-red-400"
                        data-marketing-template-blocks-error
                    >{{ formErrors.blocks }}</p>

                    <!-- Said out loud, because it is the one thing about this
                         layout that can bite: the HTML column is a result
                         here, not a source, and a hand edit to it is gone at
                         the next save. -->
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" data-marketing-template-derived-hint>
                        {{ __('marketing::templates.derived_html_hint') }}
                    </p>
                </Card>
            </Panel>

            <Panel v-else :heading="__('marketing::templates.html')">
                <Card>
                    <Field :error="formErrors.html">
                        <CodeEditor
                            :model-value="html"
                            mode="htmlmixed"
                            :line-numbers="true"
                            :line-wrapping="true"
                            data-marketing-template-code
                            @update:model-value="html = $event"
                        />
                    </Field>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('marketing::templates.layout_instructions') }}
                    </p>

                    <!-- The list, rather than two examples in a sentence. The
                         placeholders are the whole vocabulary of a layout, and
                         guessing at one that sounds right produces a gap in the
                         mail and no error anywhere. -->
                    <details class="mt-3">
                        <summary class="cursor-pointer text-xs font-medium text-gray-600 dark:text-gray-400">
                            {{ __('marketing::templates.available_variables') }}
                        </summary>
                        <div class="mt-2 flex flex-wrap gap-1" data-marketing-template-variables>
                            <code
                                v-for="name in (availableVariables || [])"
                                :key="name"
                                class="rounded bg-gray-100 px-1.5 py-0.5 text-2xs dark:bg-gray-800"
                             v-text="placeholder(name)"></code>
                        </div>
                    </details>
                </Card>
            </Panel>

            <Panel :heading="__('marketing::templates.preview')" class="lg:sticky lg:top-4">
                <Card>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <!-- Most of these mails are read on a phone, and a
                                 layout that only ever gets looked at at 900px wide
                                 is a layout whose first real test is a subscriber's
                                 thumb. -->
                            <ToggleGroup
                                :model-value="previewWidth"
                                size="sm"
                                :aria-label="__('marketing::templates.preview_device')"
                                data-marketing-template-preview-device
                                @update:model-value="(value) => { if (value) previewWidth = value; }"
                            >
                                <ToggleItem value="desktop" :label="__('marketing::templates.preview_desktop')" />
                                <ToggleItem value="mobile" :label="__('marketing::templates.preview_mobile')" />
                            </ToggleGroup>

                            <!-- And the same question about the device's theme.
                                 A layout with dark-mode rules is otherwise only
                                 testable by sending yourself a mail and changing
                                 your phone's settings. -->
                            <ToggleGroup
                                :model-value="previewScheme"
                                size="sm"
                                :aria-label="__('marketing::templates.preview_scheme')"
                                data-marketing-template-preview-scheme
                                @update:model-value="(value) => { if (value) previewScheme = value; }"
                            >
                                <ToggleItem value="light" :label="__('marketing::templates.preview_light')" />
                                <ToggleItem value="dark" :label="__('marketing::templates.preview_dark')" />
                            </ToggleGroup>
                        </div>

                        <span v-if="previewStale" class="text-xs text-amber-600 dark:text-amber-400">
                            {{ __('marketing::templates.preview_stale') }}
                        </span>
                    </div>

                    <p v-if="previewError" class="mb-3 text-sm text-red-600 dark:text-red-400" data-marketing-template-preview-error>
                        {{ previewError }}
                    </p>

                    <div
                        v-if="previewHtml"
                        class="marketing-email-canvas mx-auto overflow-hidden rounded-lg border border-gray-200 transition-[max-width] dark:border-gray-800"
                        :class="[
                            previewWidth === 'mobile' ? 'max-w-[390px]' : 'max-w-full',
                            previewScheme === 'dark' ? 'marketing-email-canvas--dark' : '',
                        ]"
                    >
                        <!-- `:key` on the scheme, so the frame is rebuilt when
                             it changes. `color-scheme` on the element is what
                             makes `prefers-color-scheme` answer dark inside the
                             framed document, and a document that has already
                             parsed its media queries in the other scheme does
                             not always re-evaluate them in place. Cheap: srcdoc
                             is already in memory, nothing is fetched. -->
                        <iframe
                            :key="previewScheme"
                            :srcdoc="previewHtml"
                            :sandbox="previewSandbox"
                            class="marketing-email-canvas h-[640px] w-full border-0"
                            :class="previewScheme === 'dark' ? 'marketing-email-canvas--dark' : ''"
                            :title="__('marketing::templates.preview')"
                            data-marketing-template-preview
                        ></iframe>
                    </div>

                    <p v-else class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('marketing::templates.preview_empty') }}
                    </p>
                </Card>
            </Panel>
        </div>

        <ConfirmationModal
            :open="showDeleteConfirm"
            :title="__('marketing::templates.delete')"
            :body-text="__('marketing::templates.delete_confirm')"
            danger
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
