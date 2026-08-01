<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header, Panel, Button, Badge, Card, Heading, Subheading, Text,
    Table, TableColumns, TableColumn, TableRows, TableRow, TableCell,
    EmptyStateMenu, EmptyStateItem, CommandPaletteItem,
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
        <Header :title="__('Marketing')" icon="mail">
            <CommandPaletteItem
                :category="__('Marketing')"
                :text="__('Create list')"
                :url="createListUrl"
                icon="mail"
            >
                <Button :href="createListUrl" :text="__('Create list')" variant="default" />
            </CommandPaletteItem>
            <CommandPaletteItem
                :category="__('Marketing')"
                :text="__('Create campaign')"
                :url="createCampaignUrl"
                icon="mail-send-email-attachment-document"
            >
                <Button :href="createCampaignUrl" :text="__('Create campaign')" variant="primary" />
            </CommandPaletteItem>
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
                    <!--
                        Native Table rather than a hand-built one. The markup
                        here used to carry its own header colours, its own
                        divider and its own row padding, all of which drift the
                        first time the CP theme changes — and the empty state
                        was a centred sentence with no way out of it, on the
                        landing screen of the addon.
                    -->
                    <EmptyStateMenu v-if="lists.length === 0" :heading="__('No lists yet.')">
                        <EmptyStateItem
                            icon="mail"
                            :href="createListUrl"
                            :heading="__('Create list')"
                            :description="__('A mailing list is what people subscribe to and what a campaign is sent from.')"
                        />
                    </EmptyStateMenu>
                    <Table v-else>
                        <TableColumns>
                            <TableColumn>{{ __('Name') }}</TableColumn>
                            <TableColumn>{{ __('Subscribed') }}</TableColumn>
                            <TableColumn>{{ __('Pending') }}</TableColumn>
                        </TableColumns>
                        <TableRows>
                            <TableRow v-for="list in lists" :key="list.handle">
                                <TableCell>
                                    <Link :href="list.url" class="font-medium hover:underline">
                                        {{ list.name }}
                                    </Link>
                                </TableCell>
                                <TableCell>{{ list.subscribed }}</TableCell>
                                <TableCell>
                                    <Text size="sm" variant="subtle">{{ list.pending }}</Text>
                                </TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </Card>
            </Panel>

            <!-- Recent campaigns -->
            <Panel :heading="__('Recent campaigns')">
                <Card>
                    <EmptyStateMenu v-if="recentCampaigns.length === 0" :heading="__('No campaigns sent yet.')">
                        <EmptyStateItem
                            icon="mail-send-email-attachment-document"
                            :href="createCampaignUrl"
                            :heading="__('Create campaign')"
                            :description="__('Compose an email, pick a list, and send it now or on a schedule.')"
                        />
                    </EmptyStateMenu>
                    <Table v-else>
                        <TableColumns>
                            <TableColumn>{{ __('Name') }}</TableColumn>
                            <TableColumn>{{ __('Status') }}</TableColumn>
                            <TableColumn>{{ __('Recipients') }}</TableColumn>
                            <TableColumn>{{ __('Open rate') }}</TableColumn>
                        </TableColumns>
                        <TableRows>
                            <TableRow v-for="campaign in recentCampaigns" :key="campaign.handle">
                                <TableCell>
                                    <Link :href="campaign.url" class="font-medium hover:underline">
                                        {{ campaign.name }}
                                    </Link>
                                    <Text v-if="campaign.sent_at" size="xs" variant="subtle" class="block mt-0.5">{{ formatDate(campaign.sent_at) }}</Text>
                                </TableCell>
                                <TableCell>
                                    <Badge :color="statusColor(campaign.status)" :text="campaign.status" />
                                </TableCell>
                                <TableCell>{{ campaign.recipients ?? '—' }}</TableCell>
                                <TableCell>{{ campaign.open_rate != null ? `${campaign.open_rate}%` : '—' }}</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </Card>
            </Panel>
        </div>
    </div>
</template>
