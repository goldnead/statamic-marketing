<script setup>
// Eine Liste anlegen. Nur das.
//
// Bis 07.09.2026 war diese Datei zweierlei: das Anlegen-Formular und ein
// zweites Bearbeiten-Formular auf eigener Seite. Das Zweite ist entfallen —
// die Detailseite ist jetzt das Formular, wie beim Collection-Entry. Was hier
// stand, um beide Faelle zu bedienen (updateUrl, deleteUrl, das
// Loeschen-Modal, `isCreating`), ist mit ihm gegangen: dieselben drei Felder in
// zwei Dateien sind zwei Stellen, an denen die naechste Aenderung gemacht oder
// vergessen wird.
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Alert, Button, Field, Input, Select, Textarea,
} from '@statamic/cms/ui';

const props = defineProps([
    'storeUrl',             // POST endpoint
    'defaultDoubleOptIn',   // bool — die Vorgabe aus der Config
]);

const name = ref('');
const handle = ref('');
const description = ref('');

// 'default' = der Config folgen, 'on'/'off' = ausdrueckliche Abweichung.
const doubleOptIn = ref('default');

const doubleOptInOptions = computed(() => [
    { value: 'default', label: `${__('Default')} (${props.defaultDoubleOptIn ? __('On') : __('Off')})` },
    { value: 'on', label: __('On') },
    { value: 'off', label: __('Off') },
]);

// Eine abgewiesene Liste sah frueher aus wie ein toter Speichern-Knopf: die
// Antwort kam mit Fehlern zurueck, nichts wurde geschrieben, und der Bildschirm
// aenderte sich nicht. Fehler landen jetzt an dem Feld, zu dem sie gehoeren.
const formErrors = ref({});

const fieldKeys = ['name', 'handle', 'description', 'double_opt_in'];

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(([key]) => ! fieldKeys.includes(key))
        .map(([, message]) => message)
);

function save() {
    if (! name.value.trim()) return;

    router.post(props.storeUrl, {
        name: name.value,
        handle: handle.value || null,
        description: description.value || null,
        double_opt_in: doubleOptIn.value === 'default' ? null : doubleOptIn.value === 'on',
    }, {
        preserveScroll: true,
        onError: (errors) => { formErrors.value = errors || {}; },
        onSuccess: () => { formErrors.value = {}; },
    });
}
</script>

<template>
    <Head :title="[__('Create list'), __('Lists'), __('Marketing')]" />

    <div class="max-w-3xl mx-auto">
        <Header :title="__('Create list')" icon="layout-list">
            <Button :text="__('Save')" variant="primary" :disabled="!name.trim()" @click="save" />
        </Header>

        <Alert v-if="generalErrors.length" variant="error" class="mb-4" data-marketing-form-errors>
            <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
        </Alert>

        <Panel :heading="__('Details')">
            <Card>
                <div class="space-y-4">
                    <Field :label="__('Name')" :error="formErrors.name">
                        <Input v-model="name" :placeholder="__('e.g. Newsletter')" />
                    </Field>

                    <Field :label="__('Handle')" :error="formErrors.handle">
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
    </div>
</template>
