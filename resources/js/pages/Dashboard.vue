<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header, Panel, Button, Badge, Card, Heading, Subheading, Text,
} from '@statamic/cms/ui';

const props = defineProps([
    'totalSubscribed',      // int
    'totalPending',         // int
    'listCount',            // int
    'lists',                // [{ handle, name, subscribed, pending, url }]
    'recentCampaigns',      // [{ handle, name, subject, status, sent_at, url, recipients, open_rate, ... }]
    'createCampaignUrl',    // string
    'createListUrl',        // string
]);

const statTiles = computed(() => [
    { label: __('Subscribed'), value: props.totalSubscribed },
    { label: __('Pending'), value: props.totalPending },
    { label: __('Lists'), value: props.listCount },
]);

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
</script>

<template>
    <Head :title="[__('Marketing'), __('Dashboard')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Marketing')" icon="email">
            <Button :href="createListUrl" :text="__('Create list')" variant="default" />
            <Button :href="createCampaignUrl" :text="__('Create campaign')" variant="primary" />
        </Header>

        <!-- Stat tiles -->
        <div class="grid gap-4 md:grid-cols-3 mb-6">
            <Card v-for="tile in statTiles" :key="tile.label" class="h-full">
                <Subheading :text="tile.label" />
                <Heading size="2xl" class="mt-2" :text="String(tile.value)" />
            </Card>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <!-- Lists -->
            <Panel :heading="__('Lists')">
                <Card>
                    <div v-if="lists.length === 0" class="py-6 text-sm text-gray-500 text-center">
                        {{ __('No lists yet.') }}
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <th class="pb-2 font-normal">{{ __('Name') }}</th>
                                <th class="pb-2 font-normal text-right">{{ __('Subscribed') }}</th>
                                <th class="pb-2 font-normal text-right">{{ __('Pending') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-content-border">
                            <tr v-for="list in lists" :key="list.handle">
                                <td class="py-2.5">
                                    <Link :href="list.url" class="font-medium hover:underline">
                                        {{ list.name }}
                                    </Link>
                                </td>
                                <td class="py-2.5 text-right">{{ list.subscribed }}</td>
                                <td class="py-2.5 text-right text-gray-500 dark:text-gray-400">{{ list.pending }}</td>
                            </tr>
                        </tbody>
                    </table>
                </Card>
            </Panel>

            <!-- Recent campaigns -->
            <Panel :heading="__('Recent campaigns')">
                <Card>
                    <div v-if="recentCampaigns.length === 0" class="py-6 text-sm text-gray-500 text-center">
                        {{ __('No campaigns sent yet.') }}
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <th class="pb-2 font-normal">{{ __('Name') }}</th>
                                <th class="pb-2 font-normal">{{ __('Status') }}</th>
                                <th class="pb-2 font-normal text-right">{{ __('Recipients') }}</th>
                                <th class="pb-2 font-normal text-right">{{ __('Open rate') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-content-border">
                            <tr v-for="campaign in recentCampaigns" :key="campaign.handle">
                                <td class="py-2.5">
                                    <Link :href="campaign.url" class="font-medium hover:underline">
                                        {{ campaign.name }}
                                    </Link>
                                    <Text v-if="campaign.sent_at" size="xs" variant="subtle" class="block mt-0.5">{{ formatDate(campaign.sent_at) }}</Text>
                                </td>
                                <td class="py-2.5">
                                    <Badge :color="statusColor(campaign.status)" :text="campaign.status" />
                                </td>
                                <td class="py-2.5 text-right">{{ campaign.recipients ?? '—' }}</td>
                                <td class="py-2.5 text-right">{{ campaign.open_rate != null ? `${campaign.open_rate}%` : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </Card>
            </Panel>
        </div>
    </div>
</template>
