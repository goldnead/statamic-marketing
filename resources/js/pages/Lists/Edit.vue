<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Field, Input, Select, Textarea, ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'list',                 // { handle, name, description, double_opt_in } | null on create
    'storeUrl',             // POST endpoint (create only)
    'updateUrl',            // PATCH endpoint (edit only)
    'deleteUrl',            // DELETE endpoint (edit only)
    'defaultDoubleOptIn',   // bool — the config default used when double_opt_in is null
]);

const isCreating = computed(() => ! props.updateUrl);

const name = ref(props.list?.name || '');
const handle = ref(props.list?.handle || '');
const description = ref(props.list?.description || '');

// null = use the config default, true/false = explicit override.
const doubleOptIn = ref(
    props.list?.double_opt_in === true ? 'on'
        : props.list?.double_opt_in === false ? 'off'
        : 'default'
);

const doubleOptInOptions = computed(() => [
    { value: 'default', label: `${__('Default')} (${props.defaultDoubleOptIn ? __('On') : __('Off')})` },
    { value: 'on', label: __('On') },
    { value: 'off', label: __('Off') },
]);

const showDeleteConfirm = ref(false);

function payload() {
    return {
        name: name.value,
        ...(isCreating.value ? { handle: handle.value || null } : {}),
        description: description.value || null,
        double_opt_in: doubleOptIn.value === 'default' ? null : doubleOptIn.value === 'on',
    };
}

// A rejected list used to look like a dead Save button: the response came back
// with errors, nothing was written, and the screen did not change. Errors now
// land on the field they belong to.
const formErrors = ref({});

// Keys rendered next to their own field. Anything else has no field to sit at
// and goes into the summary above the form, or it would be invisible again.
const fieldKeys = ['name', 'handle', 'description', 'double_opt_in'];

// Which of those keys actually has a field on screen right now. The handle
// input is only rendered while creating (`v-if="isCreating"`), so on an update
// a rejected handle has nowhere to sit — and being on the list above would
// filter it out of the summary as "already shown at its field". It would then
// be shown nowhere at all, which is the exact failure 1.5.3 set out to end.
const keysWithAVisibleField = computed(() =>
    fieldKeys.filter((key) => key !== 'handle' || isCreating.value)
);

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! keysWithAVisibleField.value.includes(key))
        .map(([, message]) => message)
);

function save() {
    if (! name.value.trim()) return;

    const options = {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    };

    if (isCreating.value) {
        router.post(props.storeUrl, payload(), options);
    } else {
        router.patch(props.updateUrl, payload(), options);
    }
}

function destroy() {
    router.delete(props.deleteUrl, {
        onError: (errors) => { formErrors.value = errors || {}; },
    });
}
</script>

<template>
    <Head :title="[isCreating ? __('Create list') : list.name, __('Lists'), __('Marketing')]" />

    <div class="max-w-3xl mx-auto">
        <Header :title="isCreating ? __('Create list') : name" icon="list">
            <Button
                v-if="deleteUrl"
                :text="__('Delete')"
                variant="danger"
                @click="showDeleteConfirm = true"
            />
            <Button :text="__('Save')" variant="primary" :disabled="!name.trim()" @click="save" />
        </Header>

        <Panel v-if="generalErrors.length" class="mb-4" data-marketing-form-errors>
            <div class="p-4 text-sm text-red-600 dark:text-red-400">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
            </div>
        </Panel>

        <Panel :heading="__('Details')">
            <Card>
                <div class="space-y-4">
                    <Field :label="__('Name')" :error="formErrors.name">
                        <Input v-model="name" :placeholder="__('e.g. Newsletter')" />
                    </Field>

                    <Field v-if="isCreating" :label="__('Handle')" :error="formErrors.handle">
                        <Input v-model="handle" placeholder="newsletter" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Lowercase letters, numbers and underscores (snake_case). Leave empty to generate from the name.') }}
                        </p>
                    </Field>

                    <Field :label="__('Description')" :error="formErrors.description">
                        <Textarea v-model="description" rows="3" :placeholder="__('Optional description for this list.')" />
                    </Field>

                    <Field :label="__('Double opt-in')" :error="formErrors.double_opt_in">
                        <Select v-model="doubleOptIn" :options="doubleOptInOptions" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Whether new subscribers must confirm their email address before being subscribed.') }}
                        </p>
                    </Field>
                </div>
            </Card>
        </Panel>

        <ConfirmationModal
            :open="showDeleteConfirm"
            :title="__('Delete list')"
            :body-text="__('Delete this list and all of its subscriptions? This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
