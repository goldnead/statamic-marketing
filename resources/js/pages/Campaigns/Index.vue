<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Alert, Badge, Button, DropdownItem, ConfirmationModal, Text,
    Tabs, TabList, TabTrigger,
} from '@statamic/cms/ui';
import { campaignStatusColor } from '../../support/campaignStatus.js';
import { relativeTime } from '../../support/relativeTime.js';

const props = defineProps([
    'campaigns',    // [{ id, handle, name, subject, list, status, status_label, series,
                    //   children_count, event: { city, date, time } | null, scheduled_at, sent_at,
                    //   recipients, open_rate, show_url, edit_url, delete_url, editable }]
    'columns',      // Array<Column>
    'createUrl',    // string
    'canManage',    // bool
    'tabs',         // [{ name: 'all' | 'awaiting_approval' | 'series', label, count }]
]);

// -- Tabs --------------------------------------------------------------------
//
// Everything, what waits for a release, and the templates that produce it.
// The listing is client-side, so a tab only narrows the rows already here; it
// still lives in the query string, so a reload or a shared link lands on it.
const tabNames = computed(() => (props.tabs || []).map((t) => t.name));

function tabFromUrl() {
    try {
        const status = new URLSearchParams(window.location.search).get('status');
        return status && tabNames.value.includes(status) ? status : 'all';
    } catch (e) {
        return 'all';
    }
}

const activeTab = ref(tabFromUrl());

watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    if (tab === 'all') url.searchParams.delete('status');
    else url.searchParams.set('status', tab);
    window.history.replaceState(window.history.state, '', url.toString());
});

const isAwaitingTab = computed(() => activeTab.value === 'awaiting_approval');

// The waiting tab is a queue: soonest send first, and "immediately on
// approval" (no time) before everything, because it is the most urgent. The
// listing's own sorting is switched off there so this order holds.
const visibleCampaigns = computed(() => {
    if (activeTab.value === 'all') return props.campaigns;

    const rows = props.campaigns.filter((campaign) => campaign.status === activeTab.value);

    if (! isAwaitingTab.value) return rows;

    return [...rows].sort((a, b) => (a.scheduled_at || '').localeCompare(b.scheduled_at || ''));
});

// A waiting row goes to its approval page, not to the editor: reviewing is
// what the row is waiting for.
function rowUrl(row) {
    if (row.status === 'awaiting_approval') return row.show_url;
    return row.editable && props.canManage ? row.edit_url : row.show_url;
}

function childrenLabel(count) {
    if (! count) return __('marketing::campaigns.series_children_none');
    if (count === 1) return __('marketing::campaigns.series_children_one');
    return __('marketing::campaigns.series_children_many', { count });
}

function formatDate(value) {
    return value
        ? new Date(value).toLocaleString(undefined, {
            day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
        })
        : null;
}

const campaignToDelete = ref(null);

// A refused delete used to be silent here: the response came back with errors,
// the row stayed, and nothing said why. There is no field on this page to hang
// a message on, so everything that comes back is shown above the listing.
const formErrors = ref({});

const generalErrors = computed(() => Object.values(formErrors.value));

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function confirmDelete(campaign) {
    campaignToDelete.value = campaign;
}

function destroy() {
    if (! campaignToDelete.value) return;
    router.delete(campaignToDelete.value.delete_url, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
        onFinish: () => { campaignToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('Campaigns'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('Campaigns')" icon="mail">
            <Button
                v-if="canManage"
                :href="createUrl"
                :text="__('Create campaign')"
                variant="primary"
            />
        </Header>

        <Alert v-if="generalErrors.length" variant="error" class="mb-4" data-marketing-form-errors>
            <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
        </Alert>

        <Tabs v-if="tabs && tabs.length" v-model="activeTab" class="mb-4" data-marketing-campaign-tabs>
            <TabList :aria-label="__('marketing::campaigns.tabs_label')">
                <TabTrigger v-for="tab in tabs" :key="tab.name" :name="tab.name" :data-marketing-campaign-tab="tab.name">
                    {{ tab.label }}
                    <Badge v-if="tab.count > 0" :text="String(tab.count)" pill class="ms-1.5" />
                </TabTrigger>
            </TabList>
        </Tabs>

        <Listing
            :items="visibleCampaigns"
            :columns="columns"
            :allow-presets="false"
            :sortable="!isAwaitingTab"
            preferences-prefix="marketing.campaigns"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <Link :href="rowUrl(row)" class="font-medium hover:underline">
                    {{ row.name }}
                </Link>
                <!-- What a series row is, in one line under its name: a
                     template says how many campaigns it made, a child says
                     where and when its term is. -->
                <div v-if="row.status === 'series'" class="text-xs text-gray-500 dark:text-gray-400" data-marketing-series-children>
                    {{ childrenLabel(row.children_count) }}
                </div>
                <div v-else-if="row.event" class="text-xs text-gray-500 dark:text-gray-400" data-marketing-series-term>
                    {{ __('marketing::series.term') }}: {{ row.event.time
                        ? __('marketing::series.term_value', { date: row.event.date, time: row.event.time })
                        : row.event.date }}
                </div>
            </template>

            <template #cell-scheduled_at="{ row }">
                <span v-if="row.scheduled_at" class="text-xs text-gray-500 dark:text-gray-400">
                    {{ formatDate(row.scheduled_at) }}<template v-if="row.status === 'awaiting_approval' || row.status === 'scheduled'">
                        <span class="block" data-marketing-relative-send>{{ relativeTime(row.scheduled_at) }}</span>
                    </template>
                </span>
                <span v-else-if="row.status === 'awaiting_approval'" class="text-xs text-gray-500 dark:text-gray-400">{{ __('marketing::campaigns.send_on_approval') }}</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #cell-subject="{ row }">
                <Text size="sm">{{ row.subject }}</Text>
            </template>

            <template #cell-list="{ row }">
                <span v-if="row.list" class="text-xs text-gray-500">{{ row.list }}</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #cell-status="{ row }">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :color="campaignStatusColor(row.status)" :text="row.status_label || row.status" pill />
                    <!-- The one thing a waiting row asks for, in reach
                         without opening the row menu. -->
                    <Button
                        v-if="row.status === 'awaiting_approval'"
                        :href="row.show_url"
                        :text="__('marketing::series.review')"
                        size="xs"
                        data-marketing-review
                    />
                </div>
            </template>

            <template #cell-recipients="{ row }">
                <span v-if="row.recipients > 0">{{ row.recipients }}</span>
                <!-- Not sent yet: the circle around the venue it will go to. -->
                <span
                    v-else-if="row.audience != null"
                    class="text-gray-500 dark:text-gray-400"
                    :title="__('marketing::series.audience_hint')"
                    data-marketing-audience
                >{{ row.audience }}</span>
                <span v-else-if="row.recipients != null">{{ row.recipients }}</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #cell-open_rate="{ row }">
                <span v-if="row.open_rate != null">{{ row.open_rate }}%</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="row.status === 'awaiting_approval'"
                    :text="__('marketing::campaigns.review')"
                    icon="checkmark"
                    :href="row.show_url"
                />
                <DropdownItem
                    :text="__('View report')"
                    icon="charts-donut-graph"
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
