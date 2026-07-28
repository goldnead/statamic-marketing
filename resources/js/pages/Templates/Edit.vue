<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Field, Input, Textarea, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'template',      // { handle, name, html } | null on create
    'storeUrl',      // POST endpoint (create only)
    'updateUrl',     // PATCH endpoint (edit only)
    'deleteUrl',     // DELETE endpoint (edit only)
    'starterHtml',   // string — prefill on create
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

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! fieldKeys.includes(key))
        .map(([, message]) => message)
);

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

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="isCreating ? __('Create template') : name" icon="template">
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

        <Panel :heading="__('Layout HTML')">
            <Card>
                <Field :error="formErrors.html">
                    <Textarea
                        v-model="html"
                        rows="24"
                        class="font-mono text-sm"
                        :placeholder="__('The full HTML layout of the email...')"
                    />
                </Field>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('The layout must contain') }} <code v-text="contentTag"></code>
                    {{ __('where the campaign content is injected, and may use') }}
                    <code v-text="unsubscribeTag"></code>.
                </p>
            </Card>
        </Panel>

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
