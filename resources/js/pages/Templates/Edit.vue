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

function save() {
    if (! name.value.trim()) return;

    const payload = {
        name: name.value,
        ...(isCreating.value ? { handle: handle.value || null } : {}),
        html: html.value || null,
    };

    if (isCreating.value) {
        router.post(props.storeUrl, payload, { preserveScroll: true });
    } else {
        router.patch(props.updateUrl, payload, { preserveScroll: true });
    }
}

function destroy() {
    router.delete(props.deleteUrl);
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

        <Panel :heading="__('Details')" class="mb-4">
            <Card>
                <div class="space-y-4">
                    <Field :label="__('Name')">
                        <Input v-model="name" :placeholder="__('e.g. Newsletter layout')" />
                    </Field>

                    <Field v-if="isCreating" :label="__('Handle')">
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
                <Textarea
                    v-model="html"
                    rows="24"
                    class="font-mono text-sm"
                    :placeholder="__('The full HTML layout of the email...')"
                />
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('The layout must contain') }} <code v-text="contentTag"></code>
                    {{ __('where the campaign content is injected, and may use') }}
                    <code v-text="unsubscribeTag"></code>.
                </p>
            </Card>
        </Panel>

        <ConfirmationModal
            v-if="showDeleteConfirm"
            :title="__('Delete template')"
            :message="__('Delete this template? Campaigns using it will fall back to the built-in default layout.')"
            variant="danger"
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
