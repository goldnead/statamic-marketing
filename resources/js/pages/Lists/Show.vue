<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Button, Badge, Field, Input, Select, Listing,
    DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'list',                    // { handle, name, description, double_opt_in, double_opt_in_effective }
    'stats',                   // { subscribed, pending, unsubscribed, bounced, complained, total }
    'subscribers',             // [{ id, email, name, status, subscribed_at, contact_uuid, unsubscribe_url, delete_url }]
    'columns',                 // Array<Column>
    'pagination',              // { current_page, last_page, total }
    'filters',                 // { status, search }
    'editUrl',                 // string
    'addSubscriberUrl',        // POST endpoint
    'canManageSubscribers',    // bool
    'canManage',               // bool
]);

const status = ref(props.filters.status || '');
const search = ref(props.filters.search || '');

const newEmail = ref('');
const newFirstName = ref('');
const newLastName = ref('');

const subscriberToDelete = ref(null);

const statusOptions = computed(() => [
    { value: '', label: __('All statuses') },
    { value: 'subscribed', label: __('Subscribed') },
    { value: 'pending', label: __('Pending') },
    { value: 'unsubscribed', label: __('Unsubscribed') },
    { value: 'bounced', label: __('Bounced') },
    { value: 'complained', label: __('Complained') },
]);

const statBadges = computed(() => [
    { label: __('subscribed'), value: props.stats.subscribed, color: 'green' },
    { label: __('pending'), value: props.stats.pending, color: 'yellow' },
    { label: __('unsubscribed'), value: props.stats.unsubscribed, color: 'default' },
    { label: __('bounced'), value: props.stats.bounced, color: 'red' },
]);

function statusColor(key) {
    return {
        subscribed: 'green',
        pending: 'yellow',
        unsubscribed: 'default',
        bounced: 'red',
        complained: 'red',
    }[key] || 'default';
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function query(page = 1) {
    const params = {};
    if (status.value) params.status = status.value;
    if (search.value) params.search = search.value;
    if (page > 1) params.page = page;
    return params;
}

function applyFilters() {
    router.get(window.location.pathname, query(), {
        preserveState: true,
        preserveScroll: true,
    });
}

function goToPage(page) {
    if (page < 1 || page > props.pagination.last_page) return;
    router.get(window.location.pathname, query(page), {
        preserveState: true,
        preserveScroll: true,
    });
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function addSubscriber() {
    if (! newEmail.value.trim()) return;
    router.post(props.addSubscriberUrl, {
        email: newEmail.value,
        first_name: newFirstName.value || null,
        last_name: newLastName.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            newEmail.value = '';
            newFirstName.value = '';
            newLastName.value = '';
        },
    });
}

function unsubscribe(row) {
    router.post(row.unsubscribe_url, {}, { preserveScroll: true });
}

function confirmDelete(row) {
    subscriberToDelete.value = row;
}

function destroy() {
    if (! subscriberToDelete.value) return;
    router.delete(subscriberToDelete.value.delete_url, {
        preserveScroll: true,
        onFinish: () => { subscriberToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[list.name, __('Lists'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="list.name" icon="list">
            <Button v-if="canManage" :href="editUrl" :text="__('Edit')" variant="default" />
        </Header>

        <div class="flex flex-wrap items-center gap-2 -mt-4 mb-6">
            <Badge
                v-for="stat in statBadges"
                :key="stat.label"
                :color="stat.color"
                :text="`${stat.value} ${stat.label}`"
            />
        </div>

        <p v-if="list.description" class="text-sm text-gray-500 dark:text-gray-400 -mt-2 mb-4">
            {{ list.description }}
        </p>

        <!-- Add subscriber -->
        <Panel v-if="canManageSubscribers" class="mb-4">
            <div class="p-4 flex flex-col sm:flex-row gap-2 items-start sm:items-end">
                <Field :label="__('Email')" class="flex-1">
                    <Input v-model="newEmail" type="email" placeholder="jane@example.com" />
                </Field>
                <Field :label="__('First name')" class="w-full sm:w-44">
                    <Input v-model="newFirstName" :placeholder="__('Optional')" />
                </Field>
                <Field :label="__('Last name')" class="w-full sm:w-44">
                    <Input v-model="newLastName" :placeholder="__('Optional')" />
                </Field>
                <Button :text="__('Add subscriber')" variant="primary" :disabled="!newEmail.trim()" @click="addSubscriber" />
            </div>
        </Panel>

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-2 mb-4 sm:items-end">
            <Field :label="__('Status')">
                <Select v-model="status" :options="statusOptions" @update:model-value="applyFilters" />
            </Field>
            <Field :label="__('Search')" class="flex-1 sm:max-w-xs">
                <Input v-model="search" :placeholder="__('Search email or name...')" @keyup.enter="applyFilters" />
            </Field>
            <Button :text="__('Filter')" variant="default" @click="applyFilters" />
        </div>

        <!-- Subscribers. Search, status filtering and pagination are handled
             server-side (above / below), so the Listing's own client-side
             search/sort/column tools are disabled — otherwise they'd render a
             second search box and only ever operate on the current page. -->
        <Listing
            :items="subscribers"
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

            <template #cell-name="{ row }">
                <span v-if="row.name">{{ row.name }}</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #cell-status="{ row }">
                <Badge :color="statusColor(row.status)" :text="row.status" />
            </template>

            <template #cell-subscribed_at="{ row }">
                <span class="text-xs text-gray-500">{{ formatDate(row.subscribed_at) }}</span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManageSubscribers && row.status !== 'unsubscribed'"
                    :text="__('Unsubscribe')"
                    icon="archive"
                    @click="unsubscribe(row)"
                />
                <DropdownItem
                    v-if="canManageSubscribers"
                    :text="__('Delete')"
                    icon="trash"
                    @click="confirmDelete(row)"
                />
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

        <ConfirmationModal
            :open="subscriberToDelete !== null"
            :title="__('Delete subscriber')"
            :body-text="__('Permanently delete this subscription? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="subscriberToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
