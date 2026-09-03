<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Alert, Button, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'templates',    // [{ id, handle, name, edit_url, delete_url }]
    'columns',      // Array<Column>
    'createUrl',    // string
    'canManage',    // bool
]);

const templateToDelete = ref(null);

// A refused delete used to be silent here: the response came back with errors,
// the row stayed, and nothing said why. There is no field on this page to hang
// a message on, so everything that comes back is shown above the listing.
const formErrors = ref({});

const generalErrors = computed(() => Object.values(formErrors.value));

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function confirmDelete(template) {
    templateToDelete.value = template;
}

function destroy() {
    if (! templateToDelete.value) return;
    router.delete(templateToDelete.value.delete_url, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
        onFinish: () => { templateToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('marketing::templates.title'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('marketing::templates.title')" icon="template-theme-design-layout">
            <Button
                v-if="canManage"
                :href="createUrl"
                :text="__('marketing::templates.create')"
                variant="primary"
            />
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('marketing::templates.intro') }}
        </p>

        <Alert v-if="generalErrors.length" variant="error" class="mb-4" data-marketing-form-errors>
            <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
        </Alert>

        <Listing
            :items="templates"
            :columns="columns"
            preferences-prefix="marketing.templates"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <Link v-if="canManage" :href="row.edit_url" class="font-medium hover:underline">
                    {{ row.name }}
                </Link>
                <span v-else class="font-medium">{{ row.name }}</span>
            </template>

            <template #cell-handle="{ row }">
                <span class="text-xs text-gray-500">{{ row.handle }}</span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManage"
                    :text="__('Edit')"
                    icon="edit"
                    :href="row.edit_url"
                />
                <DropdownItem
                    v-if="canManage"
                    :text="__('Delete')"
                    icon="trash"
                    @click="confirmDelete(row)"
                />
            </template>
        </Listing>

        <ConfirmationModal
            :open="templateToDelete !== null"
            :title="__('marketing::templates.delete')"
            :body-text="__('marketing::templates.delete_confirm')"
            danger
            :button-text="__('Delete')"
            @cancel="templateToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
