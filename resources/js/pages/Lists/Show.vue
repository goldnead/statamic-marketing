<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Alert, Button, Badge, Field, Input, Select, Listing,
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

function statusLabel(key) {
    return __(`marketing::subscribers.statuses.${key}`);
}

const statusOptions = computed(() => [
    { value: '', label: __('marketing::subscribers.filter.all_statuses') },
    { value: 'subscribed', label: statusLabel('subscribed') },
    { value: 'pending', label: statusLabel('pending') },
    { value: 'unsubscribed', label: statusLabel('unsubscribed') },
    { value: 'bounced', label: statusLabel('bounced') },
    { value: 'complained', label: statusLabel('complained') },
]);

const statBadges = computed(() => [
    { label: statusLabel('subscribed'), value: props.stats.subscribed, color: 'green' },
    { label: statusLabel('pending'), value: props.stats.pending, color: 'yellow' },
    { label: statusLabel('unsubscribed'), value: props.stats.unsubscribed, color: 'default' },
    { label: statusLabel('bounced'), value: props.stats.bounced, color: 'red' },
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

// A rejected address used to look like a dead Add button: the response came
// back with errors, no subscriber was written, and the screen did not change.
// Errors now land on the field they belong to.
const formErrors = ref({});

// Keys rendered next to their own field. Anything else — a refused unsubscribe,
// a refused delete — has no field to sit at and goes into the summary above the
// add form, or it would be invisible again.
const fieldKeys = ['email', 'first_name', 'last_name'];

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! fieldKeys.includes(key))
        .map(([, message]) => message)
);

function addSubscriber() {
    if (! newEmail.value.trim()) return;
    router.post(props.addSubscriberUrl, {
        email: newEmail.value,
        first_name: newFirstName.value || null,
        last_name: newLastName.value || null,
    }, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => {
            formErrors.value = {};
            newEmail.value = '';
            newFirstName.value = '';
            newLastName.value = '';
        },
    });
}

function unsubscribe(row) {
    router.post(row.unsubscribe_url, {}, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    });
}

function confirmDelete(row) {
    subscriberToDelete.value = row;
}

function destroy() {
    if (! subscriberToDelete.value) return;
    router.delete(subscriberToDelete.value.delete_url, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
        onFinish: () => { subscriberToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[list.name, __('Lists'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <Header :title="list.name" icon="layout-list">
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

        <Alert v-if="generalErrors.length" variant="error" class="mb-4" data-marketing-form-errors>
            <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
        </Alert>

        <!-- Add subscriber -->
        <Panel v-if="canManageSubscribers" :heading="__('marketing::subscribers.add')" class="mb-4">
            <!-- The padding belongs to Card, not to the grey Panel: inputs and
                 buttons on bare grey are the loudest non-core signal there is
                 (ui-vocabulary §9 antipattern 19). -->
            <Card>
                <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-end">
                    <!-- Not `flex-1`: that gives a flex-basis of 0, and Field
                         brings its own `min-w-0`, so the column collapsed to zero
                         width and the next field sat on top of it. An explicit
                         width is what the two neighbours already use. -->
                    <Field :label="__('Email')" class="w-full sm:w-80" :error="formErrors.email">
                        <Input v-model="newEmail" type="email" placeholder="jane@example.com" />
                    </Field>
                    <Field :label="__('marketing::subscribers.first_name')" class="w-full sm:w-44" :error="formErrors.first_name">
                        <Input v-model="newFirstName" :placeholder="__('marketing::subscribers.optional')" />
                    </Field>
                    <Field :label="__('marketing::subscribers.last_name')" class="w-full sm:w-44" :error="formErrors.last_name">
                        <Input v-model="newLastName" :placeholder="__('marketing::subscribers.optional')" />
                    </Field>
                    <Button :text="__('marketing::subscribers.add')" variant="primary" :disabled="!newEmail.trim()" @click="addSubscriber" />
                </div>
            </Card>
        </Panel>

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-2 mb-4 sm:items-end">
            <Field :label="__('Status')">
                <Select v-model="status" :options="statusOptions" @update:model-value="applyFilters" />
            </Field>
            <!-- The last `flex-1` on a Field in this addon, and the same trap
                 v1.5.1 fixed one row up: `flex-1` is `flex: 1 1 0%`, and
                 Field's own recipe carries `min-w-0`, which removes the
                 min-content floor that would otherwise stop the column
                 collapsing. `sm:max-w-xs` cannot help — a max-width is not a
                 floor. An explicit width is what the add-subscriber row uses. -->
            <Field :label="__('Search')" class="w-full sm:w-72">
                <Input v-model="search" :placeholder="__('marketing::subscribers.search_placeholder')" @keyup.enter="applyFilters" />
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
                <Badge :color="statusColor(row.status)" :text="statusLabel(row.status)" />
            </template>

            <template #cell-subscribed_at="{ row }">
                <span class="text-xs text-gray-500">{{ formatDate(row.subscribed_at) }}</span>
            </template>

            <!-- Where this contact stands against the frequency cap, and how
                 many campaigns have actually been held back from them. The
                 second number is the one somebody asks about: "capped" on a
                 campaign report names a message, this names a person. -->
            <template #cell-frequency="{ row }">
                <span v-if="!row.frequency" class="text-2xs text-gray-400">—</span>
                <span v-else class="inline-flex items-center gap-2">
                    <Badge
                        :color="row.frequency.at_limit ? 'orange' : 'default'"
                        :text="`${row.frequency.sent}/${row.frequency.limit}`"
                    />
                    <span
                        v-if="row.frequency.held_back"
                        class="text-xs text-gray-500"
                        :title="__('marketing::subscribers.frequency_held_back_hint')"
                    >
                        {{ row.frequency.held_back }} {{ __('marketing::subscribers.frequency_held_back') }}
                    </span>
                </span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManageSubscribers && row.status !== 'unsubscribed'"
                    :text="__('marketing::subscribers.actions.unsubscribe')"
                    icon="x-square"
                    @click="unsubscribe(row)"
                />
                <DropdownItem
                    v-if="canManageSubscribers"
                    :text="__('marketing::subscribers.actions.delete')"
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
            :title="__('marketing::subscribers.delete_confirm.title')"
            :body-text="__('marketing::subscribers.delete_confirm.message')"
            danger
            :button-text="__('marketing::subscribers.actions.delete')"
            @cancel="subscriberToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
