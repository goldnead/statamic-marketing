<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Alert, Button, Badge, Field, Input, Select, Textarea, Listing,
    Dropdown, DropdownMenu, DropdownItem, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'list',                    // { handle, name, description, double_opt_in, double_opt_in_effective }
    'stats',                   // { subscribed, pending, unsubscribed, bounced, complained, total }
    'subscribers',             // [{ id, email, name, status, subscribed_at, contact_uuid, unsubscribe_url, delete_url }]
    'columns',                 // Array<Column>
    'pagination',              // { current_page, last_page, total }
    'filters',                 // { status, search }
    'updateUrl',               // PATCH endpoint — die Detailseite ist das Formular
    'deleteUrl',               // DELETE endpoint
    'defaultDoubleOptIn',      // bool — die Vorgabe aus der Config
    'addSubscriberUrl',        // POST endpoint
    'canManageSubscribers',    // bool
    'canManage',               // bool
]);

const status = ref(props.filters.status || '');
const search = ref(props.filters.search || '');

// -- Die Liste selbst ------------------------------------------------------
//
// Bis hierher fuehrte jede Aenderung auf ein zweites Formular auf einer
// eigenen Seite. Beim Collection-Entry gibt es diesen Bruch nicht: die
// Detailseite ist das Formular, Speichern sitzt oben rechts, Loeschen im
// "…"-Menue daneben. Genau so hier.
//
// Eigene Fehler-Ablage statt der geteilten unten: auf dieser Seite stehen zwei
// Formulare (Liste und "Abonnent hinzufuegen"). Eine gemeinsame Ablage haette
// beim Speichern der Liste die Fehler des anderen Formulars weggeraeumt und
// umgekehrt.
const name = ref(props.list.name || '');
const description = ref(props.list.description || '');

// null = die Vorgabe aus der Config, true/false = ausdrueckliche Abweichung.
const doubleOptIn = ref(
    props.list.double_opt_in === true ? 'on'
        : props.list.double_opt_in === false ? 'off'
        : 'default'
);

const doubleOptInOptions = computed(() => [
    { value: 'default', label: `${__('Default')} (${props.defaultDoubleOptIn ? __('On') : __('Off')})` },
    { value: 'on', label: __('On') },
    { value: 'off', label: __('Off') },
]);

const detailErrors = ref({});
const detailFieldKeys = ['name', 'description', 'double_opt_in'];

const detailGeneralErrors = computed(() =>
    Object.entries(detailErrors.value)
        .filter(([key]) => ! detailFieldKeys.includes(key))
        .map(([, message]) => message)
);

const showListDeleteConfirm = ref(false);

function saveList() {
    if (! name.value.trim()) return;

    router.patch(props.updateUrl, {
        name: name.value,
        description: description.value || null,
        double_opt_in: doubleOptIn.value === 'default' ? null : doubleOptIn.value === 'on',
    }, {
        preserveScroll: true,
        onError: (errors) => { detailErrors.value = errors || {}; },
        onSuccess: () => { detailErrors.value = {}; },
    });
}

function destroyList() {
    router.delete(props.deleteUrl, {
        onError: (errors) => { detailErrors.value = errors || {}; },
        onFinish: () => { showListDeleteConfirm.value = false; },
    });
}

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
        <Header :title="canManage ? name : list.name" icon="layout-list">
            <!-- Loeschen sitzt im "…"-Menue neben Speichern, nicht als eigener
                 Knopf und nicht in der Seitenleiste — wie beim Entry. -->
            <Dropdown v-if="canManage && deleteUrl">
                <DropdownMenu>
                    <DropdownItem
                        :text="__('Delete')"
                        icon="trash"
                        variant="destructive"
                        @click="showListDeleteConfirm = true"
                    />
                </DropdownMenu>
            </Dropdown>
            <Button
                v-if="canManage && updateUrl"
                :text="__('Save')"
                variant="primary"
                :disabled="!name.trim()"
                @click="saveList"
            />
        </Header>

        <div class="flex flex-wrap items-center gap-2 -mt-4 mb-6">
            <Badge
                v-for="stat in statBadges"
                :key="stat.label"
                :color="stat.color"
                :text="`${stat.value} ${stat.label}`"
            />
        </div>

        <!-- Nur fuer Leser ohne Schreibrecht. Wer schreiben darf, sieht die
             Beschreibung als Feld weiter unten statt zweimal. -->
        <p v-if="! canManage && list.description" class="text-sm text-gray-500 dark:text-gray-400 -mt-2 mb-4">
            {{ list.description }}
        </p>

        <Alert v-if="detailGeneralErrors.length" variant="error" class="mb-4" data-marketing-list-errors>
            <p v-for="(message, index) in detailGeneralErrors" :key="index">{{ message }}</p>
        </Alert>

        <!-- Die Liste selbst, editierbar. -->
        <Panel v-if="canManage && updateUrl" :heading="__('Details')" class="mb-4">
            <Card>
                <div class="space-y-4">
                    <Field :label="__('Name')" :error="detailErrors.name">
                        <Input v-model="name" :placeholder="__('e.g. Newsletter')" />
                    </Field>

                    <Field :label="__('Description')" :error="detailErrors.description">
                        <Textarea v-model="description" rows="3" :placeholder="__('Optional description for this list.')" />
                    </Field>

                    <Field :label="__('Double opt-in')" :error="detailErrors.double_opt_in">
                        <Select v-model="doubleOptIn" :options="doubleOptInOptions" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Whether new subscribers must confirm their email address before being subscribed.') }}
                        </p>
                    </Field>
                </div>
            </Card>
        </Panel>

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

        <ConfirmationModal
            :open="showListDeleteConfirm"
            :title="__('Delete list')"
            :body-text="__('Delete this list and all of its subscriptions? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="showListDeleteConfirm = false"
            @confirm="destroyList"
        />
    </div>
</template>
