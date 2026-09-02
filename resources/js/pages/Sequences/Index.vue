<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Listing, Panel, Badge, Button, DropdownItem, ConfirmationModal, Text,
    Icon, EmptyStateMenu, EmptyStateItem, CommandPaletteItem, Alert,
} from '@statamic/cms/ui';

const props = defineProps([
    'sequences',            // [{ id, handle, title, trigger, trigger_label, list, steps, state,
                            //   automation_url, last_run_at, edit_url, delete_url }]
    'columns',              // Array<Column>
    'createUrl',            // string
    'canManage',            // bool
    'automationsAvailable', // bool — false: sequences can be written but nothing runs
]);

const isEmpty = computed(() => props.sequences.length === 0);

const sequenceToDelete = ref(null);

// A refused delete has no field to sit at, so whatever comes back is shown
// above the listing — the same rule the campaign and list screens follow.
const formErrors = ref({});

const generalErrors = computed(() => Object.values(formErrors.value));

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function stateColor(state) {
    return {
        enabled: 'green',
        disabled: 'default',
        unavailable: 'amber',
        detached: 'red',
    }[state] || 'default';
}

function stateLabel(state) {
    return __(`marketing::sequences.states.${state}`);
}

function confirmDelete(sequence) {
    sequenceToDelete.value = sequence;
}

function destroy() {
    if (! sequenceToDelete.value) return;
    router.delete(sequenceToDelete.value.delete_url, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
        onFinish: () => { sequenceToDelete.value = null; },
    });
}
</script>

<template>
    <Head :title="[__('marketing::sequences.title'), __('Marketing')]" />

    <div class="max-w-page mx-auto">
        <template v-if="isEmpty">
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="mail" class="size-5 text-gray-500" />{{ __('marketing::sequences.title') }}
                </h1>
            </header>

            <EmptyStateMenu :heading="__('marketing::sequences.empty_heading')">
                <EmptyStateItem
                    v-if="canManage"
                    :href="createUrl"
                    icon="mail"
                    :heading="__('marketing::sequences.empty_create')"
                    :description="__('marketing::sequences.empty_create_description')"
                />
            </EmptyStateMenu>

            <div v-if="!automationsAvailable" class="mx-auto mt-6 max-w-2xl">
                <Alert
                    variant="warning"
                    :heading="__('marketing::sequences.empty_automations')"
                    :text="__('marketing::sequences.empty_automations_description')"
                />
            </div>
        </template>

        <template v-else>
            <Header :title="__('marketing::sequences.title')" icon="mail">
                <CommandPaletteItem
                    v-if="canManage"
                    category="Actions"
                    :text="__('marketing::sequences.create')"
                    icon="mail"
                    :url="createUrl"
                    v-slot="{ text, url }"
                >
                    <Button :href="url" :text="text" variant="primary" />
                </CommandPaletteItem>
            </Header>

            <Alert
                v-if="!automationsAvailable"
                class="mb-4"
                variant="warning"
                :text="__('marketing::sequences.unavailable_note')"
            />

            <Panel v-if="generalErrors.length" class="mb-4" data-marketing-form-errors>
                <div class="p-4 text-sm text-red-600 dark:text-red-400">
                    <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
                </div>
            </Panel>

            <Listing
                :items="sequences"
                :columns="columns"
                preferences-prefix="marketing.sequences"
                @refreshing="reloadPage"
            >
                <template #cell-title="{ row }">
                    <Link v-if="canManage" :href="row.edit_url" class="font-medium hover:underline">
                        {{ row.title }}
                    </Link>
                    <span v-else class="font-medium">{{ row.title }}</span>
                    <div class="text-xs text-gray-500">{{ row.handle }}</div>
                </template>

                <template #cell-trigger_label="{ row }">
                    <Text size="sm">{{ row.trigger_label }}</Text>
                    <div v-if="row.trigger_label !== row.trigger" class="text-xs text-gray-500">{{ row.trigger }}</div>
                </template>

                <template #cell-list="{ row }">
                    <span v-if="row.list" class="text-xs text-gray-500">{{ row.list }}</span>
                    <span v-else class="text-2xs text-gray-400">—</span>
                </template>

                <template #cell-steps="{ row }">
                    <Badge color="default" :text="String(row.steps)" />
                </template>

                <template #cell-state="{ row }">
                    <Badge :color="stateColor(row.state)" :text="stateLabel(row.state)" />
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem
                        v-if="canManage"
                        :text="__('Edit')"
                        icon="edit"
                        :href="row.edit_url"
                    />
                    <DropdownItem
                        v-if="row.automation_url"
                        :text="__('marketing::sequences.open_automation')"
                        icon="flow"
                        :href="row.automation_url"
                    />
                    <DropdownItem
                        v-if="canManage"
                        :text="__('Delete')"
                        icon="trash"
                        @click="confirmDelete(row)"
                    />
                </template>
            </Listing>
        </template>

        <ConfirmationModal
            :open="sequenceToDelete !== null"
            :title="__('marketing::sequences.delete')"
            :body-text="__('marketing::sequences.delete_confirm')"
            danger
            :button-text="__('Delete')"
            @cancel="sequenceToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
