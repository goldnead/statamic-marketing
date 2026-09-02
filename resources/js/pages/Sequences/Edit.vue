<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Field, Input, Select, Textarea, Switch,
    ConfirmationModal, Text, Description, Alert,
} from '@statamic/cms/ui';

const props = defineProps([
    'sequence',                // { handle, title, trigger, trigger_config, list, enabled, state,
                               //   automation_url, steps: [{ template, subject_override, delay_amount, delay_unit }] } | null
    'storeUrl',                // POST (create only)
    'updateUrl',               // PATCH (edit only)
    'deleteUrl',               // DELETE (edit only)
    'triggers',                // [{ value, label, group, schema: [{ handle, label, type, options, required, help, default }] }]
    'templates',               // [{ value, label, subject }]
    'lists',                   // [{ value, label }]
    'units',                   // [{ value, label }]
    'automationsAvailable',    // bool
    'unavailableReason',       // 'unavailable' | 'unsupported_storage' | null
    'emailTemplatesAvailable', // bool
]);

// Two different reasons a sequence does not run, and they need two different
// sentences: "automations is not installed" is wrong when it is installed and
// merely keeping its flows in files.
const unavailableNote = computed(() => (
    props.unavailableReason === 'unsupported_storage'
        ? __('marketing::sequences.unsupported_storage_note')
        : __('marketing::sequences.unavailable_note')
));

const isCreating = computed(() => ! props.updateUrl);

const title = ref(props.sequence?.title || '');
const handle = ref(props.sequence?.handle || '');
const trigger = ref(props.sequence?.trigger || '');
const triggerConfig = ref({ ...(props.sequence?.trigger_config || {}) });
const list = ref(props.sequence?.list || '');
const enabled = ref(props.sequence?.enabled ?? false);

function blankStep() {
    return { template: '', subject_override: '', delay_amount: 0, delay_unit: 'days' };
}

const steps = ref(
    (props.sequence?.steps || []).length
        ? props.sequence.steps.map((step) => ({
            template: step.template || '',
            subject_override: step.subject_override || '',
            delay_amount: step.delay_amount ?? 0,
            delay_unit: step.delay_unit || 'days',
        }))
        : [blankStep()],
);

const showDeleteConfirm = ref(false);

// Set when the server refuses a save that would cut off people currently
// waiting in the sequence. Holds the sentence it sent, with the number in it.
const shrinkWarning = ref(null);

// Triggers come grouped by the addon that registered them. The group goes on
// the label, because one flat select is what core offers and a person looks
// for "Payments" before they look for "Paid".
const triggerOptions = computed(() => (props.triggers || []).map((option) => ({
    value: option.value,
    label: option.group ? `${option.group} · ${option.label}` : option.label,
})));

const selectedTrigger = computed(
    () => (props.triggers || []).find((option) => option.value === trigger.value) || null,
);

// The trigger's own fields, rendered generically. A field type this screen
// does not know falls back to a text input rather than disappearing — a
// filter nobody can set is a sequence that fires for everybody.
const triggerFields = computed(() => selectedTrigger.value?.schema || []);

function switchTrigger(next) {
    if (next === trigger.value) return;

    trigger.value = next;

    // A config written for one trigger means nothing to another.
    const fresh = {};

    for (const field of (props.triggers || []).find((option) => option.value === next)?.schema || []) {
        if (field.default !== null && field.default !== undefined) {
            fresh[field.handle] = field.default;
        }
    }

    triggerConfig.value = fresh;
}

function selectOptions(field) {
    return (field.options || []).map((option) => (
        typeof option === 'object' && option !== null
            ? { value: option.value, label: option.label ?? option.value }
            : { value: option, label: String(option) }
    ));
}

const listOptions = computed(() => props.lists || []);

const templateOptions = computed(() => props.templates || []);

function templateSubject(slug) {
    return (props.templates || []).find((option) => option.value === slug)?.subject || '';
}

function addStep() {
    steps.value.push({ ...blankStep(), delay_amount: 3 });
}

function removeStep(index) {
    if (steps.value.length === 1) return;
    steps.value.splice(index, 1);
}

function moveStep(index, direction) {
    const target = index + direction;
    if (target < 0 || target >= steps.value.length) return;
    const [step] = steps.value.splice(index, 1);
    steps.value.splice(target, 0, step);
}

function payload() {
    return {
        title: title.value,
        ...(isCreating.value ? { handle: handle.value || null } : {}),
        trigger: trigger.value,
        trigger_config: triggerConfig.value,
        list: list.value || null,
        enabled: enabled.value,
        steps: steps.value.map((step) => ({
            template: step.template,
            subject_override: step.subject_override || null,
            delay_amount: Number(step.delay_amount) || 0,
            delay_unit: step.delay_unit,
        })),
    };
}

const formErrors = ref({});

// Keys with a field on screen. Step errors arrive as `steps.2.template` and
// are matched by prefix below; everything else without a field goes into the
// summary above the form, where it can at least be read.
const fieldKeys = ['title', 'handle', 'trigger', 'list', 'enabled', 'steps'];

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! fieldKeys.some((field) => key === field || key.startsWith(`${field}.`)))
        .map(([, message]) => message)
);

function stepError(index, field) {
    return formErrors.value[`steps.${index}.${field}`];
}

const canSave = computed(() => title.value.trim() !== '' && trigger.value !== '' && list.value !== '');

// `confirmShrink` is only ever passed by the confirmation below, never by the
// Save button — which calls `save()` with no argument on purpose, because a
// bare `@click="save"` would hand it the click event and read as a yes.
function save(confirmShrink = false) {
    if (! canSave.value) return;

    const options = {
        preserveScroll: true,
        onError: (errors) => {
            const all = errors || {};

            // Taken out of the field errors and shown in its own modal: it is
            // a question, not a rejected field.
            const { confirm_shrink: shrink, ...rest } = all;

            shrinkWarning.value = shrink || null;
            formErrors.value = rest;
        },
        onSuccess: () => { formErrors.value = {}; shrinkWarning.value = null; },
    };

    const body = confirmShrink ? { ...payload(), confirm_shrink: true } : payload();

    if (isCreating.value) {
        router.post(props.storeUrl, body, options);
    } else {
        router.patch(props.updateUrl, body, options);
    }
}

function confirmShrink() {
    shrinkWarning.value = null;
    save(true);
}

function destroy() {
    router.delete(props.deleteUrl, {
        preserveScroll: true,
        // A refused delete has no field to sit at; the summary above the form
        // is where it becomes readable instead of looking like a dead button.
        onError: (errors) => { formErrors.value = errors || {}; showDeleteConfirm.value = false; },
        onSuccess: () => { formErrors.value = {}; },
    });
}

function stateColor(state) {
    return {
        enabled: 'green',
        disabled: 'default',
        unavailable: 'amber',
        detached: 'red',
    }[state] || 'default';
}
</script>

<template>
    <Head :title="[isCreating ? __('marketing::sequences.create') : sequence.title, __('marketing::sequences.title'), __('Marketing')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="isCreating ? __('marketing::sequences.create') : title" icon="mail">
            <Badge
                v-if="sequence"
                :color="stateColor(sequence.state)"
                :text="__(`marketing::sequences.states.${sequence.state}`)"
            />
            <Button
                v-if="deleteUrl"
                :text="__('Delete')"
                variant="danger"
                @click="showDeleteConfirm = true"
            />
            <Button
                :text="__('Save')"
                variant="primary"
                :disabled="!canSave"
                @click="save()"
            />
        </Header>

        <Alert
            v-if="!automationsAvailable"
            class="mb-4"
            variant="warning"
            :text="unavailableNote"
        />

        <Panel v-if="generalErrors.length" class="mb-4" data-marketing-form-errors>
            <div class="p-4 text-sm text-red-600 dark:text-red-400">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
            </div>
        </Panel>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-4">
                <Panel :heading="__('marketing::sequences.title_column')">
                    <Card>
                        <div class="space-y-4">
                            <Field :label="__('marketing::sequences.name')" :error="formErrors.title" required>
                                <Input v-model="title" :placeholder="__('marketing::sequences.name')" />
                            </Field>

                            <Field v-if="isCreating" :label="__('marketing::sequences.handle')" :error="formErrors.handle">
                                <Input v-model="handle" placeholder="after_purchase" />
                            </Field>

                            <Field
                                :label="__('marketing::sequences.trigger')"
                                :instructions="automationsAvailable ? __('marketing::sequences.trigger_help') : __('marketing::sequences.trigger_free_text')"
                                :error="formErrors.trigger"
                                required
                            >
                                <Select
                                    v-if="triggerOptions.length"
                                    :model-value="trigger"
                                    :options="triggerOptions"
                                    @update:model-value="switchTrigger"
                                />
                                <Input v-else v-model="trigger" placeholder="payments.paid" />
                            </Field>

                            <div v-if="triggerFields.length" class="space-y-4 border-t border-content-border pt-4">
                                <Text size="sm" class="font-medium">{{ __('marketing::sequences.trigger_settings') }}</Text>

                                <Field
                                    v-for="field in triggerFields"
                                    :key="field.handle"
                                    :label="field.label"
                                    :instructions="field.help"
                                    :required="field.required"
                                    :error="formErrors[`trigger_config.${field.handle}`]"
                                >
                                    <Select
                                        v-if="field.type === 'select' && selectOptions(field).length"
                                        v-model="triggerConfig[field.handle]"
                                        :options="selectOptions(field)"
                                        clearable
                                    />
                                    <Switch
                                        v-else-if="field.type === 'toggle'"
                                        v-model="triggerConfig[field.handle]"
                                    />
                                    <Textarea
                                        v-else-if="field.type === 'textarea'"
                                        v-model="triggerConfig[field.handle]"
                                        :rows="3"
                                    />
                                    <Input
                                        v-else
                                        v-model="triggerConfig[field.handle]"
                                        :type="field.type === 'number' ? 'number' : 'text'"
                                    />
                                </Field>
                            </div>
                        </div>
                    </Card>
                </Panel>

                <Panel :heading="__('marketing::sequences.steps')" :subheading="__('marketing::sequences.steps_intro')">
                    <!-- The list as a whole can be refused ("a sequence needs
                         at least one step"). That error belongs to no single
                         step, so it is rendered above them. -->
                    <p v-if="formErrors.steps" class="mb-3 text-sm text-red-600 dark:text-red-400">
                        {{ formErrors.steps }}
                    </p>

                    <Card v-for="(step, index) in steps" :key="index" class="mb-4">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between gap-2">
                                <Text size="sm" class="font-medium">{{ __('marketing::sequences.step', { n: index + 1 }) }}</Text>
                                <div class="flex items-center gap-2">
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        :text="__('marketing::sequences.move_up')"
                                        :disabled="index === 0"
                                        @click="moveStep(index, -1)"
                                    />
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        :text="__('marketing::sequences.move_down')"
                                        :disabled="index === steps.length - 1"
                                        @click="moveStep(index, 1)"
                                    />
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        :text="__('marketing::sequences.remove_step')"
                                        :disabled="steps.length === 1"
                                        @click="removeStep(index)"
                                    />
                                </div>
                            </div>

                            <Field :label="__('marketing::sequences.delay')" :error="stepError(index, 'delay_amount') || stepError(index, 'delay_unit')">
                                <div class="flex items-center gap-2">
                                    <div class="w-28">
                                        <Input v-model="step.delay_amount" type="number" min="0" max="3650" />
                                    </div>
                                    <div class="w-40">
                                        <Select v-model="step.delay_unit" :options="units" />
                                    </div>
                                    <Text v-if="Number(step.delay_amount) === 0" size="sm" class="text-gray-500">
                                        {{ __('marketing::sequences.immediately') }}
                                    </Text>
                                </div>
                            </Field>

                            <Field
                                :label="__('marketing::sequences.template')"
                                :instructions="emailTemplatesAvailable ? null : __('marketing::sequences.template_missing')"
                                :error="stepError(index, 'template')"
                                required
                            >
                                <Select
                                    v-if="templateOptions.length"
                                    v-model="step.template"
                                    :options="templateOptions"
                                    :placeholder="__('marketing::sequences.choose_template')"
                                />
                                <Input v-else v-model="step.template" placeholder="welcome-mail" />
                            </Field>

                            <Field :label="__('marketing::sequences.subject_override')" :error="stepError(index, 'subject_override')">
                                <Input v-model="step.subject_override" :placeholder="__('marketing::sequences.subject_placeholder')" />
                                <Description
                                    v-if="!step.subject_override && templateSubject(step.template)"
                                    class="mt-1"
                                    :text="__('marketing::sequences.subject_from_template', { subject: templateSubject(step.template) })"
                                />
                            </Field>
                        </div>
                    </Card>

                    <Button :text="__('marketing::sequences.add_step')" icon="plus" variant="default" @click="addStep" />
                </Panel>
            </div>

            <aside class="space-y-4">
                <Panel :heading="__('marketing::sequences.list')">
                    <Card>
                        <Field :instructions="__('marketing::sequences.list_help')" :error="formErrors.list" required>
                            <Select v-model="list" :options="listOptions" />
                        </Field>
                    </Card>
                </Panel>

                <Panel :heading="__('marketing::sequences.automation')">
                    <Card>
                        <div class="space-y-4">
                            <Field :label="__('marketing::sequences.enabled')" :instructions="__('marketing::sequences.enabled_help')" :error="formErrors.enabled">
                                <Switch v-model="enabled" />
                            </Field>

                            <div v-if="sequence" class="space-y-3 border-t border-content-border pt-4">
                                <div>
                                    <Badge
                                        :color="stateColor(sequence.state)"
                                        :text="__(`marketing::sequences.states.${sequence.state}`)"
                                    />
                                </div>
                                <Text size="sm" class="text-gray-600 dark:text-gray-400">
                                    {{ automationsAvailable ? __('marketing::sequences.managed_note') : unavailableNote }}
                                </Text>
                                <Button
                                    v-if="sequence.automation_url"
                                    :href="sequence.automation_url"
                                    :text="__('marketing::sequences.open_automation')"
                                    variant="default"
                                    size="sm"
                                />
                            </div>
                        </div>
                    </Card>
                </Panel>
            </aside>
        </div>

        <ConfirmationModal
            :open="shrinkWarning !== null"
            :title="__('marketing::sequences.shrink_title')"
            :body-text="shrinkWarning"
            danger
            :button-text="__('marketing::sequences.shrink_save')"
            @cancel="shrinkWarning = null"
            @confirm="confirmShrink"
        />

        <ConfirmationModal
            :open="showDeleteConfirm"
            :title="__('marketing::sequences.delete')"
            :body-text="__('marketing::sequences.delete_confirm')"
            danger
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
