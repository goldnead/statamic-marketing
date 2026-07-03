<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'lists',        // [{ id, handle, name, double_opt_in, subscribed, pending, show_url, edit_url, delete_url }]
    'columns',      // Array<Column>
    'createUrl',    // string
    'canManage',    // bool
]);

const listToDelete = ref(null);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function confirmDelete(list) {
    listToDelete.value = list;
}

function destroy() {
    if (! listToDelete.value) return;
    router.delete(listToDelete.value.delete_url, {
        preserveScroll: true,
        onFinish: () => { listToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Lists'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Lists')" icon="list">
            <Button
                v-if="canManage"
                :href="createUrl"
                :text="__('Create list')"
                variant="primary"
            />
        </Header>

        <Listing
            :items="lists"
            :columns="columns"
            preferences-prefix="marketing.lists"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <Link :href="row.show_url" class="font-medium hover:underline">
                    {{ row.name }}
                </Link>
            </template>

            <template #cell-handle="{ row }">
                <span class="text-xs text-gray-500">{{ row.handle }}</span>
            </template>

            <template #cell-double_opt_in="{ row }">
                <Badge :color="row.double_opt_in ? 'green' : 'default'" :text="row.double_opt_in ? __('On') : __('Off')" />
            </template>

            <template #cell-subscribed="{ row }">
                <Badge color="green" :text="String(row.subscribed)" />
            </template>

            <template #cell-pending="{ row }">
                <Badge color="default" :text="String(row.pending)" />
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
            :open="listToDelete !== null"
            :title="__('Delete list')"
            :body-text="__('Delete this list and all of its subscriptions? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="listToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
