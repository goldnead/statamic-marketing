<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Badge, Button, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'campaigns',    // [{ id, handle, name, subject, list, status, scheduled_at, sent_at,
                    //   recipients, open_rate, show_url, edit_url, delete_url, editable }]
    'columns',      // Array<Column>
    'createUrl',    // string
    'canManage',    // bool
]);

const campaignToDelete = ref(null);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function statusColor(status) {
    return {
        draft: 'default',
        scheduled: 'purple',
        sending: 'yellow',
        sent: 'green',
    }[status] || 'default';
}

function confirmDelete(campaign) {
    campaignToDelete.value = campaign;
}

function destroy() {
    if (! campaignToDelete.value) return;
    router.delete(campaignToDelete.value.delete_url, {
        preserveScroll: true,
        onFinish: () => { campaignToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Campaigns'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Campaigns')" icon="email">
            <Button
                v-if="canManage"
                :href="createUrl"
                :text="__('Create campaign')"
                variant="primary"
            />
        </Header>

        <Listing
            :items="campaigns"
            :columns="columns"
            preferences-prefix="marketing.campaigns"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <Link :href="row.editable && canManage ? row.edit_url : row.show_url" class="font-medium hover:underline">
                    {{ row.name }}
                </Link>
            </template>

            <template #cell-subject="{ row }">
                <span class="text-gray-700 dark:text-gray-300">{{ row.subject }}</span>
            </template>

            <template #cell-list="{ row }">
                <span v-if="row.list" class="text-xs text-gray-500">{{ row.list }}</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #cell-status="{ row }">
                <Badge :color="statusColor(row.status)" :text="row.status" />
            </template>

            <template #cell-recipients="{ row }">
                <span v-if="row.recipients != null">{{ row.recipients }}</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #cell-open_rate="{ row }">
                <span v-if="row.open_rate != null">{{ row.open_rate }}%</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    :text="__('View report')"
                    icon="chart-pie"
                    :href="row.show_url"
                />
                <DropdownItem
                    v-if="canManage && row.editable"
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
            :open="campaignToDelete !== null"
            :title="__('Delete campaign')"
            :body-text="__('Delete this campaign? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="campaignToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
