<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, Card, Heading, Subheading, Listing, Panel, Switch, Field, Text,
} from '@statamic/cms/ui';

const props = defineProps([
    'campaign',      // { handle, name, subject, preheader, from_name, from_email, reply_to,
                     //   list, template, content, status, scheduled_at, sent_at, archive }
    'stats',         // { recipients, sent, failed, skipped, pending, opened, open_rate,
                     //   clicked, click_rate, bounced, unsubscribed,
                     //   variants: { a: {...same, sample_size}, b: {...} } — empty unless A/B }
    'messages',      // [{ id, email, status, sent_at, opens, clicks }]
    'columns',       // Array<Column>
    'pagination',    // { current_page, last_page, total }
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

const statTiles = computed(() => [
    { label: __('Recipients'), value: props.stats.recipients },
    { label: __('Sent'), value: props.stats.sent },
    { label: __('Open rate'), value: `${props.stats.open_rate}%` },
    { label: __('Click rate'), value: `${props.stats.click_rate}%` },
    { label: __('Failed'), value: props.stats.failed },
    { label: __('Unsubscribed'), value: props.stats.unsubscribed },
    { label: __('Bounced'), value: props.stats.bounced },
]);

// One row per A/B variant. Empty for every campaign that is not a split test,
// so the whole block disappears rather than showing an empty table.
const variantRows = computed(() => Object.entries(props.stats.variants || {}).map(([variant, s]) => ({
    variant,
    subject: variant === 'b' ? props.campaign.variant_subject : props.campaign.subject,
    ...s,
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
        <Header :title="campaign.name" icon="mail">
            <Badge :color="campaignStatusColor(campaign.status)" :text="campaign.status" />
            <Button v-if="editable" :href="editUrl" :text="__('Edit')" variant="default" />
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            <span v-if="campaign.subject">{{ campaign.subject }} · </span>
            <span v-if="campaign.sent_at">{{ __('Sent') }} {{ formatDate(campaign.sent_at) }}</span>
            <span v-else-if="campaign.scheduled_at">{{ __('Scheduled for') }} {{ formatDate(campaign.scheduled_at) }}</span>
        </p>

        <Panel v-if="generalErrors.length" class="mb-4" data-marketing-form-errors>
            <div class="p-4 text-sm text-red-600 dark:text-red-400">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
            </div>
        </Panel>

        <!-- Web archive. Not on the edit form: that form closes when the
             campaign is sent, which is when this question actually gets
             asked. -->
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

        <!-- Stat tiles -->
        <div class="grid gap-4 grid-cols-2 md:grid-cols-4 lg:grid-cols-7 mb-6">
            <Card v-for="tile in statTiles" :key="tile.label" class="h-full">
                <Subheading :text="tile.label" />
                <Heading size="lg" class="mt-2" :text="String(tile.value)" />
            </Card>
        </div>

        <!-- A/B breakdown. Deliberately does not name a winner: whether a gap
             between two rates is a result or noise depends on the sample, and
             that question belongs to a test-design review, not to this table.
             The sample size is stated next to every rate so it can be asked. -->
        <div v-if="variantRows.length" class="mb-6">
            <Subheading :text="__('A/B variants')" class="mb-2" />
            <Card>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <th class="py-2 pe-4 font-medium">{{ __('Variant') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Subject') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Recipients') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Sample size') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Open rate') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Click rate') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('Bounced') }}</th>
                                <th class="py-2 font-medium">{{ __('Unsubscribed') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in variantRows" :key="row.variant" class="border-t border-gray-200 dark:border-gray-700">
                                <td class="py-2 pe-4 font-medium uppercase">{{ row.variant }}</td>
                                <td class="py-2 pe-4 text-gray-500 dark:text-gray-400">{{ row.subject || '—' }}</td>
                                <td class="py-2 pe-4">{{ row.recipients }}</td>
                                <td class="py-2 pe-4">{{ row.sample_size }}</td>
                                <td class="py-2 pe-4">{{ row.open_rate }}% <span class="text-xs text-gray-400">({{ row.opened }})</span></td>
                                <td class="py-2 pe-4">{{ row.click_rate }}% <span class="text-xs text-gray-400">({{ row.clicked }})</span></td>
                                <td class="py-2 pe-4">{{ row.bounced }}</td>
                                <td class="py-2">{{ row.unsubscribed }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Rates are measured against the sample size (delivered messages). This report does not declare a winner — read the difference against the sample size before drawing a conclusion.') }}
                </p>
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
