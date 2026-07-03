<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Field, Input, Select, Textarea,
    ConfirmationModal,
} from '@statamic/cms/ui';

const props = defineProps([
    'campaign',        // { handle, name, subject, preheader, from_name, from_email, reply_to,
                       //   list, template, content, status, scheduled_at, sent_at } | null on create
    'storeUrl',        // POST endpoint (create only)
    'updateUrl',       // PATCH endpoint (edit only)
    'deleteUrl',       // DELETE endpoint (edit only)
    'sendUrl',         // POST endpoint (edit only)
    'scheduleUrl',     // POST endpoint (edit only)
    'unscheduleUrl',   // POST endpoint (edit only)
    'testUrl',         // POST endpoint (edit only)
    'previewUrl',      // GET, rendered HTML (edit only)
    'showUrl',         // report page (edit only)
    'lists',           // [{ value, label }]
    'segments',        // [{ value, label, members_count }] — empty if LeadHub lacks segments
    'templates',       // [{ value, label }]
    'editable',        // bool (edit only)
    'canSend',         // bool
]);

const isCreating = computed(() => ! props.updateUrl);
const isEditable = computed(() => isCreating.value || props.editable);

const name = ref(props.campaign?.name || '');
const handle = ref(props.campaign?.handle || '');
const subject = ref(props.campaign?.subject || '');
const preheader = ref(props.campaign?.preheader || '');
const list = ref(props.campaign?.list || '');
const segment = ref(props.campaign?.segment || '');
const template = ref(props.campaign?.template || '');
const fromName = ref(props.campaign?.from_name || '');
const fromEmail = ref(props.campaign?.from_email || '');
const replyTo = ref(props.campaign?.reply_to || '');
const content = ref(props.campaign?.content || '');

// Kept in script so Vue's template compiler never sees the Antlers braces.
const antlersHint = ['first_name', 'name', 'email', 'unsubscribe_url']
    .map((variable) => `{{ ${variable} }}`)
    .join(', ');

const testEmail = ref('');
const scheduledAt = ref('');
const showPreview = ref(false);
const showSendConfirm = ref(false);
const showDeleteConfirm = ref(false);

const listOptions = computed(() => [
    { value: '', label: __('Choose a list...') },
    ...(props.lists || []),
]);

const templateOptions = computed(() => [
    { value: '', label: __('Default (built-in)') },
    ...(props.templates || []),
]);

const hasSegments = computed(() => (props.segments || []).length > 0);

const segmentOptions = computed(() => [
    { value: '', label: __('Entire list (no segment)') },
    ...(props.segments || []).map((s) => ({
        value: s.value,
        label: `${s.label} (${s.members_count})`,
    })),
]);

const selectedSegmentCount = computed(() => {
    const match = (props.segments || []).find((s) => s.value === segment.value);
    return match ? match.members_count : null;
});

function statusColor(status) {
    return {
        draft: 'gray',
        scheduled: 'purple',
        sending: 'yellow',
        sent: 'green',
    }[status] || 'gray';
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function payload() {
    return {
        name: name.value,
        ...(isCreating.value ? { handle: handle.value || null } : {}),
        subject: subject.value || null,
        preheader: preheader.value || null,
        list: list.value || null,
        segment: segment.value || null,
        template: template.value || null,
        from_name: fromName.value || null,
        from_email: fromEmail.value || null,
        reply_to: replyTo.value || null,
        content: content.value || null,
    };
}

function save() {
    if (! name.value.trim()) return;

    if (isCreating.value) {
        router.post(props.storeUrl, payload(), { preserveScroll: true });
    } else {
        router.patch(props.updateUrl, payload(), { preserveScroll: true });
    }
}

function sendTest() {
    if (! testEmail.value.trim()) return;
    router.post(props.testUrl, { email: testEmail.value }, {
        preserveScroll: true,
        onSuccess: () => { testEmail.value = ''; },
    });
}

function schedule() {
    if (! scheduledAt.value) return;
    router.post(props.scheduleUrl, { scheduled_at: scheduledAt.value }, {
        preserveScroll: true,
        onSuccess: () => { scheduledAt.value = ''; },
    });
}

function unschedule() {
    router.post(props.unscheduleUrl, {}, { preserveScroll: true });
}

function sendNow() {
    showSendConfirm.value = false;
    router.post(props.sendUrl, {}, { preserveScroll: true });
}

function destroy() {
    router.delete(props.deleteUrl);
}
</script>

<template>
    <Head :title="[isCreating ? __('Create campaign') : campaign.name, __('Campaigns'), __('Marketing')]" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="isCreating ? __('Create campaign') : name" icon="email">
            <Badge
                v-if="campaign"
                :color="statusColor(campaign.status)"
                :text="campaign.status"
            />
            <Button
                v-if="deleteUrl"
                :text="__('Delete')"
                variant="danger"
                @click="showDeleteConfirm = true"
            />
            <Button
                v-if="isEditable"
                :text="__('Save')"
                variant="primary"
                :disabled="!name.trim()"
                @click="save"
            />
        </Header>

        <!-- Locked campaigns can no longer be edited -->
        <Panel v-if="!isEditable" class="mb-4">
            <div class="p-4 text-sm text-gray-700 dark:text-gray-300">
                {{ __('This campaign has been sent or is currently sending and can no longer be edited.') }}
                <Link :href="showUrl" class="font-medium hover:underline">
                    {{ __('View the report') }} →
                </Link>
            </div>
        </Panel>

        <template v-else>
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Main column -->
                <div class="lg:col-span-2 space-y-4">
                    <Panel :heading="__('Campaign')">
                        <Card>
                            <div class="space-y-4">
                                <Field :label="__('Name')">
                                    <Input v-model="name" :placeholder="__('e.g. March newsletter')" />
                                </Field>

                                <Field v-if="isCreating" :label="__('Handle')">
                                    <Input v-model="handle" placeholder="march_newsletter" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Lowercase letters, numbers and underscores (snake_case). Leave empty to generate from the name.') }}
                                    </p>
                                </Field>

                                <Field :label="__('Subject')">
                                    <Input v-model="subject" :placeholder="__('The email subject line')" />
                                </Field>

                                <Field :label="__('Preheader')">
                                    <Input v-model="preheader" :placeholder="__('Preview text shown after the subject in most inboxes')" />
                                </Field>
                            </div>
                        </Card>
                    </Panel>

                    <Panel :heading="__('Content')">
                        <Card>
                            <Textarea
                                v-model="content"
                                rows="18"
                                class="font-mono text-sm"
                                :placeholder="__('Write your email content...')"
                            />
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Antlers variables are available:') }}
                                <code v-text="antlersHint"></code>
                            </p>
                        </Card>
                    </Panel>

                    <!-- Preview -->
                    <Panel v-if="previewUrl" :heading="__('Preview')">
                        <Card>
                            <div class="flex items-center gap-2">
                                <Button
                                    :text="showPreview ? __('Hide preview') : __('Show preview')"
                                    variant="default"
                                    @click="showPreview = !showPreview"
                                />
                                <a
                                    :href="previewUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-xs text-gray-500 hover:underline"
                                >
                                    {{ __('Open in new tab') }} ↗
                                </a>
                            </div>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('The preview reflects the last saved version. Save your changes first.') }}
                            </p>
                            <iframe
                                v-if="showPreview"
                                :src="previewUrl"
                                class="mt-3 w-full h-[600px] rounded border border-content-border bg-white"
                            ></iframe>
                        </Card>
                    </Panel>
                </div>

                <!-- Sidebar -->
                <aside class="space-y-4">
                    <Panel :heading="__('Recipients')">
                        <Card>
                            <div class="space-y-4">
                                <Field :label="__('List')">
                                    <Select v-model="list" :options="listOptions" />
                                </Field>

                                <Field v-if="hasSegments" :label="__('Segment')">
                                    <Select v-model="segment" :options="segmentOptions" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <template v-if="segment && selectedSegmentCount !== null">
                                            {{ __('Narrows to subscribers who are also in this segment.') }}
                                            <Badge color="blue" :text="String(selectedSegmentCount)" />
                                            {{ __('contacts currently match.') }}
                                        </template>
                                        <template v-else>
                                            {{ __('Optionally narrow the audience to a LeadHub segment. Consent still comes from the list.') }}
                                        </template>
                                    </p>
                                </Field>

                                <Field :label="__('Template')">
                                    <Select v-model="template" :options="templateOptions" />
                                </Field>
                            </div>
                        </Card>
                    </Panel>

                    <Panel :heading="__('Sender')">
                        <Card>
                            <div class="space-y-4">
                                <Field :label="__('From name')">
                                    <Input v-model="fromName" :placeholder="__('Defaults to the site sender')" />
                                </Field>

                                <Field :label="__('From email')">
                                    <Input v-model="fromEmail" type="email" :placeholder="__('Defaults to the site sender')" />
                                </Field>

                                <Field :label="__('Reply-to')">
                                    <Input v-model="replyTo" type="email" :placeholder="__('Optional')" />
                                </Field>
                            </div>
                        </Card>
                    </Panel>

                    <!-- Send test -->
                    <Panel v-if="testUrl && canSend" :heading="__('Send test')">
                        <Card>
                            <div class="space-y-2">
                                <Field :label="__('Test recipient')">
                                    <Input v-model="testEmail" type="email" placeholder="you@example.com" />
                                </Field>
                                <Button
                                    :text="__('Send test email')"
                                    variant="default"
                                    :disabled="!testEmail.trim()"
                                    @click="sendTest"
                                />
                            </div>
                        </Card>
                    </Panel>

                    <!-- Schedule / send -->
                    <Panel v-if="updateUrl && canSend" :heading="__('Delivery')">
                        <Card>
                            <div class="space-y-4">
                                <div v-if="campaign.status === 'scheduled'" class="space-y-2">
                                    <div class="text-sm">
                                        <Badge color="purple" :text="__('Scheduled')" />
                                        <span class="ms-2 text-gray-700 dark:text-gray-300">
                                            {{ formatDate(campaign.scheduled_at) }}
                                        </span>
                                    </div>
                                    <Button :text="__('Unschedule')" variant="default" @click="unschedule" />
                                </div>

                                <div v-else class="space-y-2">
                                    <Field :label="__('Schedule for later')">
                                        <input
                                            v-model="scheduledAt"
                                            type="datetime-local"
                                            class="w-full h-10 px-3 rounded border border-content-border bg-white dark:bg-gray-900 text-sm"
                                        />
                                    </Field>
                                    <Button :text="__('Schedule')" variant="default" :disabled="!scheduledAt" @click="schedule" />
                                </div>

                                <div class="pt-4 border-t border-content-border">
                                    <Button
                                        :text="__('Send now')"
                                        variant="primary"
                                        class="w-full"
                                        @click="showSendConfirm = true"
                                    />
                                </div>
                            </div>
                        </Card>
                    </Panel>
                </aside>
            </div>
        </template>

        <ConfirmationModal
            v-if="showSendConfirm"
            :title="__('Send campaign')"
            :message="__('Send this campaign to all subscribers of the selected list now? This cannot be undone.')"
            variant="danger"
            :button-text="__('Send now')"
            @cancel="showSendConfirm = false"
            @confirm="sendNow"
        />

        <ConfirmationModal
            v-if="showDeleteConfirm"
            :title="__('Delete campaign')"
            :message="__('Delete this campaign? This cannot be undone.')"
            variant="danger"
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
