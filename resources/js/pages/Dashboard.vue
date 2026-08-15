<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header, Panel, Button, Badge, Card, Heading, Subheading, Text,
    Table, TableColumns, TableColumn, TableRows, TableRow, TableCell,
    EmptyStateMenu, EmptyStateItem, CommandPaletteItem,
} from '@statamic/cms/ui';
import BarChart from '../components/BarChart.vue';

const props = defineProps([
    'totalSubscribed',      // int
    'totalPending',         // int
    'listCount',            // int
    'lists',                // [{ handle, name, subscribed, pending, url }]
    'recentCampaigns',      // [{ handle, name, subject, status, sent_at, url, recipients, open_rate, ... }]
    'engagement',           // [{ handle, name, sent_at, url, open_rate, click_rate, sent }] — oldest first
    'growth',               // { weeks: [{ at, subscribed, unsubscribed, net }], totals }
    'createCampaignUrl',    // string
    'createListUrl',        // string
]);

const statTiles = computed(() => [
    { label: __('Subscribed'), value: props.totalSubscribed },
    { label: __('Pending'), value: props.totalPending },
    { label: __('Lists'), value: props.listCount },
]);

// -- Engagement across campaigns ---------------------------------------------
//
// The rates come off the server exactly as CampaignStats worked them out —
// the same figures the campaign's own report prints. Nothing here divides
// anything: a chart that computes its own percentage is how an addon ends up
// with two open rates that disagree, and this one has had that defect before.

const engagementSeries = computed(() => [
    { key: 'open_rate', label: __('marketing::dashboard.engagement_open_rate'), class: 'bg-green-500' },
    { key: 'click_rate', label: __('marketing::dashboard.engagement_click_rate'), class: 'bg-purple-500' },
]);

const engagementGroups = computed(() => (props.engagement || []).map((campaign) => ({
    key: campaign.handle,
    xLabel: shortDate(campaign.sent_at),
    ariaLabel: __('marketing::dashboard.engagement_campaign', {
        name: campaign.name,
        at: formatDay(campaign.sent_at),
        openrate: campaign.open_rate,
        clickrate: campaign.click_rate,
        sent: campaign.sent,
    }),
    stacks: [
        [{ series: 'open_rate', value: campaign.open_rate }],
        [{ series: 'click_rate', value: campaign.click_rate }],
    ],
})));

/**
 * What the tallest bar means, in words.
 *
 * The bars are scaled to the largest rate rather than to a fixed hundred: a
 * click rate of three percent against a full-height axis is a line nobody can
 * compare. That trade only works if the top of the axis is stated, which is
 * what this says.
 */
const engagementScale = computed(() => {
    const rates = (props.engagement || []).flatMap((campaign) => [campaign.open_rate, campaign.click_rate]);

    return __('marketing::dashboard.engagement_scale', { max: Math.max(1, ...rates) });
});

/**
 * How long a campaign needs before its bar is worth comparing.
 *
 * Opens arrive over days, not at once, so a campaign sent yesterday stands in
 * this row with a rate that is still filling up — next to campaigns that
 * finished collecting weeks ago. The bar is not wrong, it is early, and read as
 * a trend it says "engagement is falling" about nothing at all.
 *
 * A sentence rather than a greyed-out bar: the figure is real and dimming it
 * would be a second claim, made in a colour, that nobody can read off the
 * screen. This one can be read.
 */
const FRESH_HOURS = 48;

const engagementFresh = computed(() => {
    const campaigns = props.engagement || [];
    const last = campaigns[campaigns.length - 1];

    if (! last?.sent_at) return false;

    const age = (Date.now() - new Date(last.sent_at).getTime()) / 36e5;

    return age >= 0 && age < FRESH_HOURS;
});

const engagementSummary = computed(() => {
    const campaigns = props.engagement || [];

    if (campaigns.length === 0) return '';

    return __('marketing::dashboard.engagement_summary', {
        count: campaigns.length,
        first: formatDay(campaigns[0].sent_at),
        last: formatDay(campaigns[campaigns.length - 1].sent_at),
    });
});

// -- List growth -------------------------------------------------------------

const growthSeries = computed(() => [
    { key: 'subscribed', label: __('marketing::dashboard.growth_subscribed'), class: 'bg-green-500' },
    { key: 'unsubscribed', label: __('marketing::dashboard.growth_unsubscribed'), class: 'bg-red-400 dark:bg-red-500' },
]);

const growthWeeks = computed(() => props.growth?.weeks || []);

/**
 * What the tallest week means, in words.
 *
 * The same trade the engagement chart makes, and the same reason it has to be
 * said: one imported batch of five hundred addresses flattens a year of
 * organic growth into hairlines, and without the top of the axis in words
 * there is no way to tell a quiet week from a small one.
 */
const growthScale = computed(() => {
    const counts = growthWeeks.value.flatMap((week) => [week.subscribed, week.unsubscribed]);

    return __('marketing::dashboard.growth_scale', { max: Math.max(1, ...counts.map((n) => Number(n) || 0)) });
});


// A week nobody joined is drawn as an empty track, not left out. Twelve weeks
// are always twelve columns, so a quiet stretch stays visible as a quiet
// stretch instead of the bars closing ranks around it.
const growthGroups = computed(() => growthWeeks.value.map((week) => ({
    key: week.at,
    xLabel: shortDate(week.at),
    ariaLabel: __('marketing::dashboard.growth_week', {
        at: formatDay(week.at),
        plus: week.subscribed,
        minus: week.unsubscribed,
    }),
    stacks: [
        [{ series: 'subscribed', value: week.subscribed }],
        [{ series: 'unsubscribed', value: week.unsubscribed }],
    ],
})));

// Twelve empty tracks say nothing at all, so an install where nothing has
// happened yet gets the sentence instead of the chart.
const hasGrowth = computed(() => growthWeeks.value.some(
    (week) => week.subscribed > 0 || week.unsubscribed > 0
));

const growthSummary = computed(() => __('marketing::dashboard.growth_summary', {
    weeks: growthWeeks.value.length,
    plus: props.growth?.totals?.subscribed ?? 0,
    minus: props.growth?.totals?.unsubscribed ?? 0,
    net: props.growth?.totals?.net ?? 0,
}));

function shortDate(value) {
    return value ? new Date(value).toLocaleDateString(undefined, { day: '2-digit', month: '2-digit' }) : '—';
}

function formatDay(value) {
    return value ? new Date(value).toLocaleDateString() : '—';
}

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
                <Heading size="2xl" class="mt-2 tabular-nums" :text="String(tile.value)" />
            </Card>
        </div>

        <!-- The two charts are full-width blocks, one under the other, rather
             than a two-column grid.

             Not a layout preference: every addon ships its own Tailwind build
             into the same `addon-utilities` layer, and a media query adds no
             specificity — so an unprefixed column utility from whichever
             sibling stylesheet loads last wins against a `md:`-prefixed one
             here and pins the row. goldnead/statamic-automations lost a
             five-column dashboard row to exactly that. A chart is wide by
             nature and has nothing to gain from sharing a row, so this one
             does not enter the argument at all. -->
        <Panel :heading="__('marketing::dashboard.engagement_heading')" class="mb-6">
            <Card>
                <template v-if="engagementGroups.length">
                    <Text size="sm" variant="subtle" class="mb-3 block">{{ engagementScale }}</Text>
                    <BarChart
                        :label="__('marketing::dashboard.engagement_label')"
                        :summary="engagementSummary"
                        :series="engagementSeries"
                        :groups="engagementGroups"
                    />
                    <!-- One campaign is a measurement, not a trend. The bar is
                         still drawn — it is the real figure and hiding it would
                         be worse — but the screen says outright that there is
                         nothing yet to compare it against. -->
                    <Text
                        v-if="engagementGroups.length === 1"
                        size="sm"
                        variant="subtle"
                        class="mt-3 block"
                        data-marketing-engagement-single
                    >
                        {{ __('marketing::dashboard.engagement_single') }}
                    </Text>
                    <!-- The youngest bar is systematically the shortest, and
                         for a reason that has nothing to do with the campaign:
                         it has not finished collecting its opens yet. -->
                    <Text
                        v-if="engagementFresh"
                        size="sm"
                        variant="subtle"
                        class="mt-3 block"
                        data-marketing-engagement-fresh
                    >
                        {{ __('marketing::dashboard.engagement_fresh') }}
                    </Text>
                </template>
                <p v-else class="py-6 text-center text-sm text-gray-500 dark:text-gray-400" data-marketing-engagement-empty>
                    {{ __('marketing::dashboard.engagement_empty') }}
                </p>
            </Card>
        </Panel>

        <Panel :heading="__('marketing::dashboard.growth_heading')" class="mb-6">
            <Card>
                <template v-if="hasGrowth">
                    <Text size="sm" variant="subtle" class="mb-3 block">{{ growthScale }}</Text>
                    <BarChart
                        :label="__('marketing::dashboard.growth_label')"
                        :summary="growthSummary"
                        :series="growthSeries"
                        :groups="growthGroups"
                    />
                    <!-- What the two columns are actually counting. Both
                         timestamps describe the subscription as it stands
                         today rather than an immutable ledger, and a growth
                         chart that does not say so invites a conclusion it
                         cannot carry. -->
                    <Text size="sm" variant="subtle" class="mt-3 block" data-marketing-growth-note>
                        {{ __('marketing::dashboard.growth_note') }}
                    </Text>
                </template>
                <p v-else class="py-6 text-center text-sm text-gray-500 dark:text-gray-400" data-marketing-growth-empty>
                    {{ __('marketing::dashboard.growth_empty') }}
                </p>
            </Card>
        </Panel>

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
                                <TableCell class="tabular-nums">{{ list.subscribed }}</TableCell>
                                <TableCell>
                                    <Text size="sm" variant="subtle" class="tabular-nums">{{ list.pending }}</Text>
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
                                <TableCell class="tabular-nums">{{ campaign.recipients ?? '—' }}</TableCell>
                                <TableCell class="tabular-nums">{{ campaign.open_rate != null ? `${campaign.open_rate}%` : '—' }}</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </Card>
            </Panel>
        </div>
    </div>
</template>
