<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Button, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'templates',    // [{ id, handle, name, edit_url, delete_url }]
    'columns',      // Array<Column>
    'createUrl',    // string
    'canManage',    // bool
]);

const templateToDelete = ref(null);

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
        onFinish: () => { templateToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Templates'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Templates')" icon="template">
            <Button
                v-if="canManage"
                :href="createUrl"
                :text="__('Create template')"
                variant="primary"
            />
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('Templates provide the HTML layout wrapped around campaign content.') }}
        </p>

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
            v-if="templateToDelete"
            :title="__('Delete template')"
            :message="__('Delete this template? Campaigns using it will fall back to the built-in default layout.')"
            variant="danger"
            :button-text="__('Delete')"
            @cancel="templateToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
