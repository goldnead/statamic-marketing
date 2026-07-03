<script setup>
import { computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, Card, Heading, Subheading, Listing,
} from '@statamic/cms/ui';

const props = defineProps([
    'campaign',      // { handle, name, subject, preheader, from_name, from_email, reply_to,
                     //   list, template, content, status, scheduled_at, sent_at }
    'stats',         // { recipients, sent, failed, skipped, pending, opened, open_rate,
                     //   clicked, click_rate, bounced, unsubscribed }
    'messages',      // [{ id, email, status, sent_at, opens, clicks }]
    'columns',       // Array<Column>
    'pagination',    // { current_page, last_page, total }
    'editUrl',       // string
    'editable',      // bool
]);

const statTiles = computed(() => [
    { label: __('Recipients'), value: props.stats.recipients },
    { label: __('Sent'), value: props.stats.sent },
    { label: __('Open rate'), value: `${props.stats.open_rate}%` },
    { label: __('Click rate'), value: `${props.stats.click_rate}%` },
    { label: __('Failed'), value: props.stats.failed },
    { label: __('Unsubscribed'), value: props.stats.unsubscribed },
    { label: __('Bounced'), value: props.stats.bounced },
]);

function campaignStatusColor(status) {
    return {
        draft: 'default',
        scheduled: 'purple',
        sending: 'yellow',
        sent: 'green',
    }[status] || 'default';
}

function messageStatusColor(status) {
    return {
        pending: 'default',
        sent: 'green',
        failed: 'red',
        skipped: 'default',
        bounced: 'red',
    }[status] || 'default';
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function goToPage(page) {
    if (page < 1 || page > props.pagination.last_page) return;
    router.get(window.location.pathname, page > 1 ? { page } : {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="[campaign.name, __('Campaigns'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="campaign.name" icon="email">
            <Badge :color="campaignStatusColor(campaign.status)" :text="campaign.status" />
            <Button v-if="editable" :href="editUrl" :text="__('Edit')" variant="default" />
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            <span v-if="campaign.subject">{{ campaign.subject }} · </span>
            <span v-if="campaign.sent_at">{{ __('Sent') }} {{ formatDate(campaign.sent_at) }}</span>
            <span v-else-if="campaign.scheduled_at">{{ __('Scheduled for') }} {{ formatDate(campaign.scheduled_at) }}</span>
        </p>

        <!-- Stat tiles -->
        <div class="grid gap-4 grid-cols-2 md:grid-cols-4 lg:grid-cols-7 mb-6">
            <Card v-for="tile in statTiles" :key="tile.label" class="h-full">
                <Subheading :text="tile.label" />
                <Heading size="lg" class="mt-2" :text="String(tile.value)" />
            </Card>
        </div>

        <!-- Messages. Rows are paginated server-side (custom pager below), so the
             Listing's client-side search/sort would only cover the current page
             and mislead — disable the built-in toolbar and keep it a clean table. -->
        <Listing
            :items="messages"
            :columns="columns"
            :allow-search="false"
            :allow-bulk-actions="false"
            :allow-customizing-columns="false"
            :allow-presets="false"
            :sortable="false"
        >
            <template #cell-email="{ row }">
                <span class="font-medium">{{ row.email }}</span>
            </template>

            <template #cell-status="{ row }">
                <Badge :color="messageStatusColor(row.status)" :text="row.status" />
            </template>

            <template #cell-sent_at="{ row }">
                <span class="text-xs text-gray-500">{{ formatDate(row.sent_at) }}</span>
            </template>

            <template #cell-opens="{ row }">
                <span :class="row.opens > 0 ? '' : 'text-gray-400'">{{ row.opens }}</span>
            </template>

            <template #cell-clicks="{ row }">
                <span :class="row.clicks > 0 ? '' : 'text-gray-400'">{{ row.clicks }}</span>
            </template>
        </Listing>

        <!-- Pagination -->
        <div v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between">
            <Button
                :text="__('Previous')"
                variant="default"
                :disabled="pagination.current_page <= 1"
                @click="goToPage(pagination.current_page - 1)"
            />
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Page') }} {{ pagination.current_page }} / {{ pagination.last_page }} · {{ pagination.total }} {{ __('total') }}
            </span>
            <Button
                :text="__('Next')"
                variant="default"
                :disabled="pagination.current_page >= pagination.last_page"
                @click="goToPage(pagination.current_page + 1)"
            />
        </div>
    </div>
</template>
