<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Field, Input, CodeEditor, Alert,
    ToggleGroup, ToggleItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'template',      // { handle, name, html } | null on create
    'storeUrl',      // POST endpoint (create only)
    'updateUrl',     // PATCH endpoint (edit only)
    'deleteUrl',     // DELETE endpoint (edit only)
    'starterHtml',   // string — prefill on create
    'previewUrl',    // POST endpoint that renders the layout being typed
]);

const isCreating = computed(() => ! props.updateUrl);

const name = ref(props.template?.name || '');
const handle = ref(props.template?.handle || '');
const html = ref(props.template?.html ?? props.starterHtml ?? '');

const showDeleteConfirm = ref(false);

// Kept in script so Vue's template compiler never sees the Antlers braces.
const contentTag = '{{ content }}';
const unsubscribeTag = '{{ unsubscribe_url }}';

// A rejected template used to look like a dead Save button: the response came
// back with errors, nothing was written, and the screen did not change. Errors
// now land on the field they belong to.
const formErrors = ref({});

// Keys rendered next to their own field. Anything else has no field to sit at
// and goes into the summary above the form, or it would be invisible again.
const fieldKeys = ['name', 'handle', 'html'];

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
            body: JSON.stringify({ html: html.value }),
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

watch(html, schedulePreview);
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

    const payload = {
        name: name.value,
        ...(isCreating.value ? { handle: handle.value || null } : {}),
        html: html.value || null,
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
    <Head :title="[isCreating ? __('Create template') : template.name, __('Templates'), __('Marketing')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="isCreating ? __('Create template') : name" icon="template-theme-design-layout">
            <Button
                v-if="deleteUrl"
                :text="__('Delete')"
                variant="danger"
                @click="showDeleteConfirm = true"
            />
            <Button :text="__('Save')" variant="primary" :disabled="!name.trim()" @click="save" />
        </Header>

        <Panel v-if="generalErrors.length" class="mb-4" data-marketing-form-errors>
            <div class="p-4 text-sm text-red-600 dark:text-red-400">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
            </div>
        </Panel>

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
            <Panel :heading="__('marketing::templates.html')">
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
                        <code v-text="contentTag"></code> · <code v-text="unsubscribeTag"></code>
                    </p>
                </Card>
            </Panel>

            <Panel :heading="__('marketing::templates.preview')" class="lg:sticky lg:top-4">
                <Card>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <!-- Most of these mails are read on a phone, and a
                             layout that only ever gets looked at at 900px wide
                             is a layout whose first real test is a subscriber's
                             thumb. -->
                        <ToggleGroup
                            :model-value="previewWidth"
                            size="sm"
                            :aria-label="__('marketing::templates.preview')"
                            @update:model-value="(value) => { if (value) previewWidth = value; }"
                        >
                            <ToggleItem value="desktop" :label="__('marketing::templates.preview_desktop')" />
                            <ToggleItem value="mobile" :label="__('marketing::templates.preview_mobile')" />
                        </ToggleGroup>

                        <span v-if="previewStale" class="text-xs text-amber-600 dark:text-amber-400">
                            {{ __('marketing::templates.preview_stale') }}
                        </span>
                    </div>

                    <p v-if="previewError" class="mb-3 text-sm text-red-600 dark:text-red-400" data-marketing-template-preview-error>
                        {{ previewError }}
                    </p>

                    <div
                        v-if="previewHtml"
                        class="mx-auto overflow-hidden rounded-lg border border-gray-200 bg-white transition-[max-width] dark:border-gray-800"
                        :class="previewWidth === 'mobile' ? 'max-w-[390px]' : 'max-w-full'"
                    >
                        <iframe
                            :srcdoc="previewHtml"
                            :sandbox="previewSandbox"
                            class="h-[640px] w-full border-0 bg-white"
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
            :title="__('Delete template')"
            :body-text="__('Delete this template? Campaigns using it will fall back to the built-in default layout.')"
            danger
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
