<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, Card, Heading, Subheading, Listing, Panel, Alert, Switch, Field, Text,
    Select, Tabs, TabList, TabTrigger, TabContent,
    Table, TableColumns, TableColumn, TableRows, TableRow, TableCell,
} from '@statamic/cms/ui';
import BarChart from '../../components/BarChart.vue';

const props = defineProps([
    'campaign',      // { handle, name, subject, preheader, from_name, from_email, reply_to,
                     //   list, template, content, status, scheduled_at, sent_at, archive }
    'tab',           // 'overview' | 'delivery' | 'opens' | 'clicks' | 'unsubscribes'
    'tabs',          // [{ name, label }]
    'stats',         // overview only: { recipients, sent, failed, skipped, pending, opened,
                     //   open_rate, clicked, click_rate, bounced, unsubscribed,
                     //   variants: { a: {...same, sample_size}, b: {...} } — empty unless A/B }
    'humanOpens',    // overview only: { human, machine_only, human_rate }
    'activity',      // overview only: { unit, buckets: [{ at, human_opens, machine_opens, clicks }],
                     //   totals, beyond }
    'timeline',      // overview only: [{ key, at }] — only the stations that happened
    'rows',          // person tabs: [{ email, name, contact_url, ...tab-specific }]
    'columns',       // Array<Column>
    'pagination',    // person tabs: { current_page, last_page, total }
    'statuses',      // [{ value, label }] for the delivery filter
    'filters',       // { status }
    'urlBreakdown',  // clicks tab: [{ url, clicks, people }]
    'exportUrl',     // person tabs, `manage marketing campaigns` only
    'editUrl',       // string
    'editable',      // bool
    'archive',       // { enabled, released, live, sendable_only, url, update_url }
    'canManage',     // bool
]);

// Mirrors the server's `archive.released`. Kept as local state so the switch
// moves the moment it is clicked, and reconciled from the prop when the Inertia
// response comes back — the server, not the switch, decides what is published.
const released = ref(props.archive?.released ?? false);
watch(() => props.archive?.released, (value) => { released.value = value ?? false; });

const showsArchivePanel = computed(() => props.canManage && props.archive?.enabled);

// A rejected toggle used to be invisible here in exactly the way the edit form
// once was: the request came back with errors, nothing was saved, and the
// switch stayed where the click put it.
const formErrors = ref({});

// The one key this page binds to a control of its own. Anything else the
// server reports has no field to sit at and goes into the summary instead.
const fieldKeys = ['archive'];

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! fieldKeys.includes(key))
        .map(([, message]) => message)
);

function toggleArchive(value) {
    released.value = value;
    router.patch(props.archive.update_url, { archive: value }, {
        preserveScroll: true,
        // Nothing was saved, so the switch must not keep claiming otherwise.
        onError: (errors) => {
            formErrors.value = errors || {};
            released.value = props.archive.released;
        },
        onSuccess: () => { formErrors.value = {}; },
    });
}

// -- Tabs -----------------------------------------------------------------
//
// Each tab is a server round trip, because each one is a different query over
// a table that can hold fifty thousand rows per campaign. The active tab is
// therefore in the URL rather than in local state: a reload lands where the
// reader was, a shared link opens on the tab it was shared from, and the pager
// below belongs to the tab it is under.

const activeTab = ref(props.tab);
watch(() => props.tab, (value) => { activeTab.value = value; });

const status = ref(props.filters?.status || '');
watch(() => props.filters?.status, (value) => { status.value = value || ''; });

const isOverview = computed(() => props.tab === 'overview');
const showsStatusFilter = computed(() => props.tab === 'delivery');

function query({ tab = props.tab, page = 1, withStatus = true } = {}) {
    const params = {};
    if (tab !== 'overview') params.tab = tab;
    if (withStatus && tab === 'delivery' && status.value) params.status = status.value;
    if (page > 1) params.page = page;
    return params;
}

function visit(params) {
    router.get(window.location.pathname, params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function selectTab(tab) {
    if (! tab || tab === props.tab) return;
    activeTab.value = tab;
    // The page number belongs to the tab that was showing it, and the status
    // filter belongs to Delivery. Neither travels to the next tab.
    visit(query({ tab, withStatus: tab === 'delivery' }));
}

function applyFilters() {
    visit(query());
}

function goToPage(page) {
    if (! props.pagination) return;
    if (page < 1 || page > props.pagination.last_page) return;
    visit(query({ page }));
}

// The same selection the reader is looking at, as a file. Built from the
// current tab and filter rather than from a URL the server pre-baked, so the
// two cannot drift apart.
const exportHref = computed(() => {
    if (! props.exportUrl || isOverview.value) return null;
    const params = new URLSearchParams({ tab: props.tab });
    if (props.tab === 'delivery' && status.value) params.set('status', status.value);
    return `${props.exportUrl}?${params.toString()}`;
});

// -- Figures ---------------------------------------------------------------

const statTiles = computed(() => props.stats ? [
    { label: __('Recipients'), value: props.stats.recipients },
    { label: __('Sent'), value: props.stats.sent },
    { label: __('Open rate'), value: `${props.stats.open_rate}%` },
    { label: __('Click rate'), value: `${props.stats.click_rate}%` },
    { label: __('Failed'), value: props.stats.failed },
    { label: __('Unsubscribed'), value: props.stats.unsubscribed },
    { label: __('Bounced'), value: props.stats.bounced },
    // Only where it happened. A permanent "Capped 0" on every report of every
    // install that caps nothing is noise; a missing one on the report where it
    // did happen is people the tiles do not account for.
    ...(props.stats.capped ? [{ label: __('Capped'), value: props.stats.capped }] : []),
] : []);

// The open figures, told apart. `stats.opened` is unchanged and still counts
// every fetch of the pixel — it is what every earlier campaign was measured
// with. The human number sits next to it rather than replacing it, and the
// note underneath says why the two differ.
const openTiles = computed(() => {
    if (! props.stats || ! props.humanOpens) return [];

    return [
        { label: __('marketing::campaigns.report.opens_total'), value: props.stats.opened },
        { label: __('marketing::campaigns.report.opens_human'), value: props.humanOpens.human },
        { label: __('marketing::campaigns.report.opens_human_rate'), value: `${props.humanOpens.human_rate}%` },
        // Hidden where there is none, like the Capped tile: a nought here says
        // nothing, and its absence is not a claim.
        ...(props.humanOpens.machine_only
            ? [{ label: __('marketing::campaigns.report.opens_machine_only'), value: props.humanOpens.machine_only }]
            : []),
    ];
});

// -- Activity curve ---------------------------------------------------------
//
// The machine share is a series of its own, never folded into the opens. Under
// Apple's Mail Privacy Protection the pixel is fetched when the mail is
// DELIVERED, so a curve drawn from raw opens is a wall in the first hour that
// says everybody read it at once. Stacked, the reader can see how much of that
// wall is a proxy — and the sentence beneath the tiles above, the same one the
// contact timeline uses, says why.

const activitySeries = computed(() => [
    { key: 'human_opens', label: __('marketing::campaigns.report.activity_human'), class: 'bg-green-500' },
    // The same orange the prefetched badge uses on the Opens tab. One colour
    // for "a machine did this", wherever it appears.
    { key: 'machine_opens', label: __('marketing::campaigns.report.activity_machine'), class: 'bg-orange-400 dark:bg-orange-500' },
    { key: 'clicks', label: __('marketing::campaigns.report.activity_clicks'), class: 'bg-purple-500' },
]);

const activityGroups = computed(() => {
    const buckets = props.activity?.buckets || [];

    // At most eight ticks, whatever the grid. Seventy-two labelled hours is a
    // grey smear; eight is a scale somebody can read.
    const stride = Math.max(1, Math.ceil(buckets.length / 8));

    return buckets.map((bucket, index) => ({
        key: bucket.at,
        xLabel: index % stride === 0 ? activityTick(bucket.at) : '',
        ariaLabel: __('marketing::campaigns.report.activity_bucket', {
            at: activityMoment(bucket.at),
            human: bucket.human_opens,
            machine: bucket.machine_opens,
            clicks: bucket.clicks,
        }),
        // Two bars per bucket: the opens as a stack, the clicks beside them.
        // Clicks are deliberately not a third layer of the same stack — a
        // click and an open are different acts, and a stack would read as
        // "this many people", which it is not.
        stacks: [
            [
                { series: 'human_opens', value: bucket.human_opens },
                { series: 'machine_opens', value: bucket.machine_opens },
            ],
            [{ series: 'clicks', value: bucket.clicks }],
        ],
    }));
});

const activityUnitLabel = computed(() => __(props.activity?.unit === 'day'
    ? 'marketing::campaigns.report.activity_daily'
    : 'marketing::campaigns.report.activity_hourly'));

/**
 * The top of the axis: the tallest bucket a person is responsible for.
 *
 * Not the tallest stack, and that is the whole point. Apple's proxy fetches
 * the pixel for around half of every delivered campaign inside a single
 * bucket, while reading spreads over ten hours and more — so on a shared axis
 * the tallest human hour lands at a tenth of the track, a typical one at a
 * fiftieth, and hour four and hour seven are one pixel apart. The question
 * "when was it read" is then not answerable from the picture that was drawn to
 * answer it.
 *
 * Human opens and clicks together, never the sum with the machine share: the
 * two are the acts of a person and are worth comparing against each other. The
 * machine bar then runs into the ceiling, which is the honest reading of it —
 * it does not fit on this scale — and the number it really has is said in
 * words, in the scale line below and in the label of its own column.
 */
const activityMax = computed(() => {
    const buckets = props.activity?.buckets || [];

    return Math.max(
        1,
        ...buckets.map((bucket) => Number(bucket.human_opens) || 0),
        ...buckets.map((bucket) => Number(bucket.clicks) || 0),
    );
});

/** The tallest preload bucket — the one the ceiling cuts off. */
const activityMachineMax = computed(() => {
    const buckets = props.activity?.buckets || [];

    return Math.max(0, ...buckets.map((bucket) => Number(bucket.machine_opens) || 0));
});

/**
 * What the top of the axis means, in words.
 *
 * This chart needs the sentence more than the others do, because its axis is
 * deliberately too short for one of its three series. A bar that ends at the
 * ceiling has a true value the picture cannot show, so the picture says it:
 * where preloading goes over the top, the scale line names the figure it
 * reached.
 */
const activityScale = computed(() => {
    const line = __(props.activity?.unit === 'day'
        ? 'marketing::campaigns.report.activity_scale_daily'
        : 'marketing::campaigns.report.activity_scale_hourly', { max: activityMax.value });

    if (activityMachineMax.value <= activityMax.value) return line;

    return `${line} · ${__('marketing::campaigns.report.activity_scale_machine', { max: activityMachineMax.value })}`;
});

const activitySummary = computed(() => __('marketing::campaigns.report.activity_summary', {
    human: props.activity?.totals?.human_opens ?? 0,
    machine: props.activity?.totals?.machine_opens ?? 0,
    clicks: props.activity?.totals?.clicks ?? 0,
}));

/**
 * Was this campaign sent before preload detection existed?
 *
 * The server answers it against the date of the migration that added the
 * column (CampaignReport::MACHINE_DETECTION_SINCE) — every open recorded
 * before that day carries the column default, which is "a person". For the
 * tiles that was the right call and stays it: no figure moves under anybody's
 * feet on the day the update runs. For a chart whose entire subject is telling
 * the two apart it is not, so the chart says so.
 */
const activityPredatesDetection = computed(() => Boolean(props.activity?.predates_detection));

const activityDetectionSince = computed(() => {
    const since = props.activity?.detection_since;

    return since ? new Date(since).toLocaleDateString() : '';
});

/** One bucket as a moment somebody can say out loud. */
function activityMoment(at) {
    const moment = new Date(at);

    return props.activity?.unit === 'day'
        ? moment.toLocaleDateString()
        : moment.toLocaleString();
}

/**
 * The same bucket as an axis tick — short enough to sit under a bar.
 *
 * `hour12: false` against the locale's own preference, and deliberately: an
 * axis tick is about four characters wide before it starts colliding with its
 * neighbour, and "01:00 PM" is eight. It also removes the one ambiguity a
 * twelve-hour axis has, which is that half its labels appear twice a day.
 */
function activityTick(at) {
    const moment = new Date(at);

    return props.activity?.unit === 'day'
        ? moment.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit' })
        : moment.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
}

// One row per A/B variant. Empty for every campaign that is not a split test,
// so the whole block disappears rather than showing an empty table.
const variantRows = computed(() => Object.entries(props.stats?.variants || {}).map(([variant, s]) => ({
    variant,
    subject: variant === 'b' ? props.campaign.variant_subject : props.campaign.subject,
    ...s,
})));

const timelineStations = computed(() => (props.timeline || []).map((station) => ({
    ...station,
    label: __(`marketing::campaigns.report.timeline.${station.key}`),
})));

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
        // In flight, not finished. Blue rather than green: green is the answer
        // to "did this person get it", and that answer is not in yet.
        sending: 'blue',
        sent: 'green',
        failed: 'red',
        skipped: 'default',
        bounced: 'red',
        capped: 'orange',
    }[status] || 'default';
}

function messageStatusLabel(status) {
    return __(`marketing::campaigns.message_statuses.${status}`);
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '—';
}
/**
 * The date half of the subline, or nothing at all.
 *
 * A campaign that was neither sent nor scheduled has no date to report, and
 * an empty span here is what left a separator dangling above.
 */
const subline = computed(() => {
    if (props.campaign.sent_at) {
        return `${__('Sent')} ${formatDate(props.campaign.sent_at)}`;
    }

    if (props.campaign.scheduled_at) {
        return `${__('Scheduled for')} ${formatDate(props.campaign.scheduled_at)}`;
    }

    return '';
});

</script>

<template>
    <Head :title="[campaign.name, __('Campaigns'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="campaign.name" icon="mail">
            <Badge :color="campaignStatusColor(campaign.status)" :text="campaign.status" />
            <Button v-if="editable" :href="editUrl" :text="__('Edit')" variant="default" />
        </Header>

        <!-- The separator belongs to the part that follows it, not to the
             subject. A draft has a subject and no date, and carrying the dot
             with the subject left "Entwurf ·" standing on the page with
             nothing after it — the same dangling-separator wrongness the
             timeline entries were fixed for. -->
        <p v-if="campaign.subject || subline" class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            <span v-if="campaign.subject">{{ campaign.subject }}</span>
            <span v-if="campaign.subject && subline"> · </span>
            <span v-if="subline">{{ subline }}</span>
        </p>

        <Alert v-if="generalErrors.length" variant="error" class="mb-4" data-marketing-form-errors>
            <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
        </Alert>

        <!-- Web archive. Not on the edit form: that form closes when the
             campaign is sent, which is when this question actually gets
             asked. Above the tabs rather than inside one, because it is a
             decision about the campaign, not a facet of its report. -->
        <Panel v-if="showsArchivePanel" :heading="__('Web archive')" class="mb-6">
            <Card>
                <Field :label="__('Publish a public web version')" :error="formErrors.archive">
                    <Switch :model-value="released" @update:model-value="toggleArchive" />
                </Field>
                <Text size="sm" variant="subtle" class="mt-2 block">
                    {{ __('The web version is the depersonalised edition: no tracking pixel, no click counting, and greetings resolve to a neutral word. Off by default — a campaign only appears once you publish it here.') }}
                </Text>
                <Text v-if="released && archive.sendable_only" size="sm" variant="warning" class="mt-2 block">
                    {{ __('It goes live once the campaign has been sent. Until then nothing is public.') }}
                </Text>
                <p v-if="archive.live" class="mt-2">
                    <a :href="archive.url" target="_blank" rel="noopener" class="text-sm hover:underline">
                        {{ archive.url }} ↗
                    </a>
                </p>
            </Card>
        </Panel>

        <Tabs :model-value="activeTab" @update:model-value="selectTab">
            <TabList>
                <TabTrigger v-for="item in tabs" :key="item.name" :name="item.name" :text="item.label" />
            </TabList>

            <!-- Overview ------------------------------------------------- -->
            <TabContent name="overview">
                <div v-if="isOverview">
                    <!-- Stat tiles. Unchanged figures, counted the way they have
                         always been counted, so this campaign stays comparable
                         with every campaign before it. -->
                    <div class="grid gap-4 grid-cols-2 md:grid-cols-4 lg:grid-cols-7 mb-6 mt-4">
                        <!-- `lg`, not `2xl`: eight tiles across one row, where
                             the dashboard has three. -->
                        <Card v-for="tile in statTiles" :key="tile.label" class="h-full">
                            <Subheading :text="tile.label" />
                            <Heading size="lg" class="mt-2 tabular-nums" :text="String(tile.value)" />
                        </Card>
                    </div>

                    <!-- The open figures, told apart. -->
                    <div v-if="openTiles.length" class="mb-6">
                        <Subheading :text="__('marketing::campaigns.report.opens_heading')" class="mb-2" />
                        <div class="grid gap-4 grid-cols-2 md:grid-cols-4">
                            <Card v-for="tile in openTiles" :key="tile.label" class="h-full">
                                <Subheading :text="tile.label" />
                                <Heading size="2xl" class="mt-2 tabular-nums" :text="String(tile.value)" />
                            </Card>
                        </div>
                        <Text size="sm" variant="subtle" class="mt-2 block" data-marketing-prefetch-note>
                            {{ __('marketing::timeline.prefetched_note') }}
                        </Text>
                    </div>

                    <!-- When it was read. Directly under the sentence that
                         explains a prefetched open, because the orange band in
                         this chart is that sentence drawn: the reader meets
                         the explanation and then the shape it accounts for. -->
                    <div v-if="activity" class="mb-6" data-marketing-activity>
                        <Subheading :text="__('marketing::campaigns.report.activity_heading')" class="mb-2" />
                        <Card>
                            <template v-if="activityGroups.length">
                                <Text size="sm" variant="subtle" class="mb-3 block">{{ activityUnitLabel }} · {{ activityScale }}</Text>
                                <BarChart
                                    :label="activityUnitLabel"
                                    :summary="activitySummary"
                                    :series="activitySeries"
                                    :groups="activityGroups"
                                    :max="activityMax"
                                />
                                <!-- What this chart counts, next to a tile
                                     above that counts something else under a
                                     word that sounds the same. "Öffnungen
                                     gesamt" is messages with at least one
                                     open; these bars are the openings
                                     themselves, so one reader who opened five
                                     times is 1 up there and 5 down here. Two
                                     honest figures a hundred pixels apart, and
                                     the only thing missing was the sentence
                                     that says they are different questions. -->
                                <Text size="sm" variant="subtle" class="mt-3 block" data-marketing-activity-counts>
                                    {{ __('marketing::campaigns.report.activity_counts_note') }}
                                </Text>
                                <!-- Sent before the detection existed: every
                                     open on record is green because the column
                                     defaults to "a person", not because a
                                     person was there. -->
                                <Text
                                    v-if="activityPredatesDetection"
                                    size="sm"
                                    variant="subtle"
                                    class="mt-2 block"
                                    data-marketing-activity-predates
                                >
                                    {{ __('marketing::campaigns.report.activity_predates_detection', { date: activityDetectionSince }) }}
                                </Text>
                                <Text v-if="activity.beyond" size="sm" variant="subtle" class="mt-2 block">
                                    {{ __('marketing::campaigns.report.activity_beyond', { count: activity.beyond }) }}
                                </Text>
                            </template>
                            <!-- A campaign nobody has opened is an ordinary
                                 state, not a failure, and it gets a sentence
                                 rather than an empty axis — an axis with no
                                 bars on it reads as a chart that broke. -->
                            <p v-else class="py-6 text-center text-sm text-gray-500 dark:text-gray-400" data-marketing-activity-empty>
                                {{ __('marketing::campaigns.report.activity_empty') }}
                            </p>
                        </Card>
                    </div>

                    <!-- The campaign as a sequence. Stations that did not
                         happen are absent, not empty: a campaign that was never
                         scheduled has no scheduling to report, and a row saying
                         "Geplant: —" only invites the question. -->
                    <div v-if="timelineStations.length" class="mb-6">
                        <Subheading :text="__('marketing::campaigns.report.timeline_heading')" class="mb-2" />
                        <Card>
                            <ol class="text-sm">
                                <li
                                    v-for="station in timelineStations"
                                    :key="station.key"
                                    class="flex items-baseline gap-3 py-1"
                                >
                                    <span class="w-40 shrink-0 text-gray-500 dark:text-gray-400">{{ station.label }}</span>
                                    <span>{{ formatDate(station.at) }}</span>
                                </li>
                            </ol>
                        </Card>
                    </div>

                    <!-- A/B breakdown. Deliberately does not name a winner:
                         whether a gap between two rates is a result or noise
                         depends on the sample, and that question belongs to a
                         test-design review, not to this table. The sample size
                         is stated next to every rate so it can be asked. -->
                    <div v-if="variantRows.length" class="mb-6">
                        <Subheading :text="__('A/B variants')" class="mb-2" />
                        <Card>
                            <!-- Core's Table, not Listing: these are two
                                 aggregate rows (one per variant), not a record
                                 list. Nothing here can be searched, sorted,
                                 selected, paginated or acted on, which is all
                                 Listing exists to provide. -->
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableColumns>
                                        <TableColumn>{{ __('Variant') }}</TableColumn>
                                        <TableColumn>{{ __('Subject') }}</TableColumn>
                                        <TableColumn>{{ __('Recipients') }}</TableColumn>
                                        <TableColumn>{{ __('Sample size') }}</TableColumn>
                                        <TableColumn>{{ __('Open rate') }}</TableColumn>
                                        <TableColumn>{{ __('Click rate') }}</TableColumn>
                                        <TableColumn>{{ __('Bounced') }}</TableColumn>
                                        <TableColumn>{{ __('Unsubscribed') }}</TableColumn>
                                    </TableColumns>
                                    <TableRows>
                                        <TableRow v-for="row in variantRows" :key="row.variant">
                                            <TableCell><Text variant="strong" class="uppercase">{{ row.variant }}</Text></TableCell>
                                            <TableCell><Text variant="subtle">{{ row.subject || '—' }}</Text></TableCell>
                                            <TableCell>{{ row.recipients }}</TableCell>
                                            <TableCell>{{ row.sample_size }}</TableCell>
                                            <TableCell>{{ row.open_rate }}% <Text size="xs" variant="subtle">({{ row.opened }})</Text></TableCell>
                                            <TableCell>{{ row.click_rate }}% <Text size="xs" variant="subtle">({{ row.clicked }})</Text></TableCell>
                                            <TableCell>{{ row.bounced }}</TableCell>
                                            <TableCell>{{ row.unsubscribed }}</TableCell>
                                        </TableRow>
                                    </TableRows>
                                </Table>
                            </div>
                            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Rates are measured against the sample size (delivered messages). This report does not declare a winner — read the difference against the sample size before drawing a conclusion.') }}
                            </p>
                        </Card>
                    </div>
                </div>
            </TabContent>

            <!-- Person tabs ---------------------------------------------- -->
            <TabContent v-for="item in tabs.filter((t) => t.name !== 'overview')" :key="item.name" :name="item.name">
                <div v-if="tab === item.name" class="mt-4">
                    <div class="flex flex-col sm:flex-row gap-2 mb-4 sm:items-end">
                        <Field v-if="showsStatusFilter" :label="__('Status')" class="w-full sm:w-56">
                            <Select v-model="status" :options="statuses" @update:model-value="applyFilters" />
                        </Field>
                        <Button v-if="showsStatusFilter" :text="__('Filter')" variant="default" @click="applyFilters" />
                        <span class="grow" />
                        <Button
                            v-if="exportHref"
                            :href="exportHref"
                            :text="__('marketing::campaigns.report.export')"
                            variant="default"
                            data-marketing-export
                        />
                    </div>

                    <!-- Rows are paginated server-side (custom pager below), so
                         the Listing's client-side search/sort would only cover
                         the current page and mislead — disable the built-in
                         toolbar and keep it a clean table. -->
                    <Listing
                        :items="rows"
                        :columns="columns"
                        :allow-search="false"
                        :allow-bulk-actions="false"
                        :allow-customizing-columns="false"
                        :allow-presets="false"
                        :sortable="false"
                    >
                        <!-- The address, and the person behind it when LeadHub
                             holds one. No contact, no link: a row that 404s or
                             invents a page is worse than a plain address. -->
                        <template #cell-email="{ row }">
                            <a v-if="row.contact_url" :href="row.contact_url" class="font-medium hover:underline">{{ row.email }}</a>
                            <span v-else class="font-medium" :title="__('marketing::campaigns.report.no_contact')">{{ row.email }}</span>
                        </template>

                        <template #cell-name="{ row }">
                            <span v-if="row.name">{{ row.name }}</span>
                            <span v-else class="text-2xs text-gray-400">—</span>
                        </template>

                        <template #cell-status="{ row }">
                            <Badge :color="messageStatusColor(row.status)" :text="messageStatusLabel(row.status)" />
                        </template>

                        <!-- Why this one never arrived. The column has been in
                             the schema since the first migration and has never
                             been on a screen. -->
                        <template #cell-error="{ row }">
                            <span v-if="row.error" class="text-xs text-red-600 dark:text-red-400">{{ row.error }}</span>
                            <span v-else class="text-2xs text-gray-400">—</span>
                        </template>

                        <template #cell-sent_at="{ row }">
                            <span class="text-xs text-gray-500">{{ formatDate(row.sent_at) }}</span>
                        </template>

                        <template #cell-first_opened_at="{ row }">
                            <span class="text-xs text-gray-500">{{ formatDate(row.first_opened_at) }}</span>
                        </template>

                        <template #cell-last_opened_at="{ row }">
                            <span class="text-xs text-gray-500">{{ formatDate(row.last_opened_at) }}</span>
                        </template>

                        <template #cell-clicked_at="{ row }">
                            <span class="text-xs text-gray-500">{{ formatDate(row.clicked_at) }}</span>
                        </template>

                        <template #cell-unsubscribed_at="{ row }">
                            <span class="text-xs text-gray-500">{{ formatDate(row.unsubscribed_at) }}</span>
                        </template>

                        <template #cell-opens="{ row }">
                            <span :class="row.opens > 0 ? '' : 'text-gray-400'">{{ row.opens }}</span>
                        </template>

                        <template #cell-clicks="{ row }">
                            <span :class="row.clicks > 0 ? '' : 'text-gray-400'">{{ row.clicks }}</span>
                        </template>

                        <template #cell-human_opens="{ row }">
                            <span :class="row.human_opens > 0 ? '' : 'text-gray-400'">{{ row.human_opens }}</span>
                        </template>

                        <!-- Whether this person's mail was fetched for them
                             rather than read by them. Marked rather than
                             merely counted, because the number next to it is
                             the one that would otherwise be believed. -->
                        <template #cell-machine_opens="{ row }">
                            <Badge
                                v-if="row.machine_opens > 0"
                                color="orange"
                                :text="String(row.machine_opens)"
                                data-marketing-prefetched
                            />
                            <span v-else class="text-2xs text-gray-400">—</span>
                        </template>

                        <template #cell-url="{ row }">
                            <a
                                v-if="row.url"
                                :href="row.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-xs hover:underline break-all"
                            >{{ row.url }}</a>
                            <span v-else class="text-2xs text-gray-400">—</span>
                        </template>
                    </Listing>


                    <!-- Which link was followed, how often, by how many people.
                         Grouped over the campaign, not over this page: it
                         answers a question about the mail, not about fifty
                         rows of it. -->
                    <div v-if="urlBreakdown && urlBreakdown.length" class="mt-6">
                        <Subheading :text="__('marketing::campaigns.report.urls_heading')" class="mb-2" />
                        <Card>
                            <!-- Core's Table for the same reason as the A/B
                                 breakdown above: an aggregate over the whole
                                 campaign, grouped by URL. It is not the page's
                                 record list — that is the Listing above it,
                                 which paginates server-side. -->
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableColumns>
                                        <TableColumn>{{ __('marketing::campaigns.report.url') }}</TableColumn>
                                        <TableColumn>{{ __('marketing::campaigns.report.url_clicks') }}</TableColumn>
                                        <TableColumn>{{ __('marketing::campaigns.report.url_people') }}</TableColumn>
                                    </TableColumns>
                                    <TableRows>
                                        <TableRow v-for="link in urlBreakdown" :key="link.url">
                                            <TableCell>
                                                <a :href="link.url" target="_blank" rel="noopener noreferrer" class="hover:underline break-all">{{ link.url }}</a>
                                            </TableCell>
                                            <TableCell>{{ link.clicks }}</TableCell>
                                            <TableCell>{{ link.people }}</TableCell>
                                        </TableRow>
                                    </TableRows>
                                </Table>
                            </div>
                        </Card>
                    </div>

                    <!-- What the open figures mean. Same words as the
                         timeline entry a contact gets in LeadHub — one
                         explanation, not two.

                         On every tab that shows an open figure, not only on
                         the one named after it: delivery carries an `opens`
                         column too. And not on an empty tab, where it explains
                         numbers that are not there. -->
                    <Text
                        v-if="rows.length && (tab === 'opens' || tab === 'delivery')"
                        size="sm"
                        variant="subtle"
                        class="mt-4 block"
                        data-marketing-prefetch-note
                    >
                        {{ __('marketing::timeline.prefetched_note') }}
                    </Text>

                    <!-- Pagination -->
                    <div v-if="pagination && pagination.last_page > 1" class="mt-4 flex items-center justify-between">
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
            </TabContent>
        </Tabs>
    </div>
</template>
