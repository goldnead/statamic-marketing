import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { vi } from 'vitest';
import ListsEdit from '../../resources/js/pages/Lists/Edit.vue';
import ListsShow from '../../resources/js/pages/Lists/Show.vue';
import TemplatesEdit from '../../resources/js/pages/Templates/Edit.vue';
import CampaignsEdit from '../../resources/js/pages/Campaigns/Edit.vue';
import { captureRouter, press, reject, summary, visibleText } from './helpers.js';

/**
 * Whether a rejected form actually says so.
 *
 * v1.5.3 gave every submitting mask in this control panel an `onError` branch,
 * and `tests/Feature/CpValidationVisibilityTest.php` keeps them there: it reads
 * the .vue sources and fails if a form submits without handling a rejection, or
 * names a key in `fieldKeys` that no field binds. That is a structural check —
 * it proves the wiring exists, and it cannot prove the message reaches the
 * screen. Until now nothing did except a screenshot.
 *
 * These tests mount the real page, press the real button, hand back the errors
 * Laravel would have flashed, and then ask the rendered DOM whether the message
 * is there. Not "was onError called" — visible, at the field or in the summary.
 *
 * It found one hole immediately, and it is the same failure v1.5.3 set out to
 * end: `handle` sits in `fieldKeys` on the campaign, template and list forms,
 * so the summary filters it out as "already shown at its field" — but the
 * handle input is `v-if="isCreating"`. On an update there was no field, and the
 * message was therefore rendered nowhere at all. See the last describe block.
 */

beforeEach(() => {
    captureRouter.calls = captureRouter();
});

afterEach(() => {
    vi.restoreAllMocks();
});

function calls() {
    return captureRouter.calls;
}

function lastCall() {
    const recorded = calls();

    expect(recorded.length, 'the page submitted nothing').toBeGreaterThan(0);

    return recorded[recorded.length - 1];
}

/** The error a Field was handed, located by the label the template gave it. */
function errorAtField(wrapper, label) {
    const field = wrapper.findAllComponents({ name: 'Field' })
        .find((candidate) => candidate.attributes('data-attr-label') === label);

    return field?.attributes('data-attr-error');
}

describe('Lists/Edit', () => {
    function mountPage(props = {}) {
        return mount(ListsEdit, {
            props: {
                list: { handle: 'newsletter', name: 'Newsletter', description: null, double_opt_in: null },
                storeUrl: null,
                updateUrl: '/cp/marketing/lists/newsletter',
                deleteUrl: '/cp/marketing/lists/newsletter',
                defaultDoubleOptIn: true,
                ...props,
            },
        });
    }

    it('shows a rejected name at the name field', async () => {
        const wrapper = mountPage();

        press(wrapper, 'Save');
        await reject(wrapper, lastCall(), { name: 'The name field is required.' });

        expect(errorAtField(wrapper, 'Name')).toBe('The name field is required.');
    });

    it('shows an error that belongs to no field in the summary', async () => {
        const wrapper = mountPage();

        expect(summary(wrapper), 'the summary is rendered before there is anything to say').toBeNull();

        press(wrapper, 'Save');
        await reject(wrapper, lastCall(), { list: 'That list is in use and cannot be renamed.' });

        expect(summary(wrapper)?.text()).toContain('That list is in use and cannot be renamed.');
    });

    it('clears what it showed once the next attempt succeeds', async () => {
        const wrapper = mountPage();

        press(wrapper, 'Save');
        await reject(wrapper, lastCall(), { name: 'The name field is required.' });
        expect(errorAtField(wrapper, 'Name')).toBe('The name field is required.');

        press(wrapper, 'Save');
        lastCall().options.onSuccess?.();
        await wrapper.vm.$nextTick();

        expect(errorAtField(wrapper, 'Name')).toBeUndefined();
        expect(summary(wrapper)).toBeNull();
    });

    it('reports a refused delete instead of leaving the screen unchanged', async () => {
        const wrapper = mountPage();

        press(wrapper, 'Delete');
        const modal = wrapper.findComponent({ name: 'ConfirmationModal' });
        modal.vm.$attrs.onConfirm();

        await reject(wrapper, lastCall(), { list: 'Delete every campaign using this list first.' });

        expect(visibleText(wrapper)).toContain('Delete every campaign using this list first.');
    });
});

describe('Lists/Show', () => {
    function mountPage(props = {}) {
        return mount(ListsShow, {
            props: {
                list: { handle: 'newsletter', name: 'Newsletter', description: null, double_opt_in: true, double_opt_in_effective: true },
                stats: { subscribed: 3, pending: 1, unsubscribed: 0, bounced: 0, complained: 0, total: 4 },
                subscribers: [],
                columns: [],
                pagination: { current_page: 1, last_page: 1, total: 0 },
                filters: { status: '', search: '' },
                editUrl: '/cp/marketing/lists/newsletter/edit',
                addSubscriberUrl: '/cp/marketing/lists/newsletter/subscribers',
                canManageSubscribers: true,
                canManage: true,
                ...props,
            },
        });
    }

    it('shows a rejected address at the e-mail field', async () => {
        const wrapper = mountPage();

        wrapper.vm.newEmail = 'not-an-address';
        await wrapper.vm.$nextTick();

        press(wrapper, 'marketing::subscribers.add');
        await reject(wrapper, lastCall(), { email: 'The email must be a valid email address.' });

        expect(errorAtField(wrapper, 'Email')).toBe('The email must be a valid email address.');
    });

    it('does not repeat a field-level error in the summary', async () => {
        const wrapper = mountPage();

        wrapper.vm.newEmail = 'not-an-address';
        await wrapper.vm.$nextTick();

        press(wrapper, 'marketing::subscribers.add');
        await reject(wrapper, lastCall(), { email: 'The email must be a valid email address.' });

        expect(summary(wrapper), 'the message is at the field and in the summary as well').toBeNull();
    });

    it('shows a refused unsubscribe, which has no field to sit at', async () => {
        const wrapper = mountPage();

        wrapper.vm.unsubscribe({ unsubscribe_url: '/cp/marketing/subscriptions/1/unsubscribe' });
        await reject(wrapper, lastCall(), { subscription: 'This subscriber is already unsubscribed.' });

        expect(summary(wrapper)?.text()).toContain('This subscriber is already unsubscribed.');
    });
});

describe('Campaigns/Edit', () => {
    function mountPage(props = {}) {
        return mount(CampaignsEdit, {
            props: {
                campaign: {
                    handle: 'march', name: 'March', subject: 'Hi', preheader: null,
                    from_name: null, from_email: null, reply_to: null,
                    list: 'newsletter', segment: '', template: '', content: 'Body',
                    status: 'draft', scheduled_at: null, sent_at: null,
                },
                storeUrl: null,
                updateUrl: '/cp/marketing/campaigns/march',
                deleteUrl: '/cp/marketing/campaigns/march',
                sendUrl: '/cp/marketing/campaigns/march/send',
                scheduleUrl: '/cp/marketing/campaigns/march/schedule',
                unscheduleUrl: '/cp/marketing/campaigns/march/unschedule',
                testUrl: '/cp/marketing/campaigns/march/test',
                previewUrl: '/cp/marketing/campaigns/march/preview',
                showUrl: '/cp/marketing/campaigns/march',
                lists: [{ value: 'newsletter', label: 'Newsletter' }],
                segments: [],
                templates: [],
                editable: true,
                canSend: true,
                ...props,
            },
        });
    }

    it('shows a refused send, which belongs to no input', async () => {
        const wrapper = mountPage();

        wrapper.vm.sendNow();
        await reject(wrapper, lastCall(), { send: 'This campaign has no list and cannot be sent.' });

        expect(summary(wrapper)?.text()).toContain('This campaign has no list and cannot be sent.');
    });

    it('shows a rejected test recipient at the test recipient field', async () => {
        const wrapper = mountPage();

        wrapper.vm.testEmail = 'nope';
        await wrapper.vm.$nextTick();

        press(wrapper, 'Send test email');
        await reject(wrapper, lastCall(), { email: 'The email must be a valid email address.' });

        expect(errorAtField(wrapper, 'Test recipient')).toBe('The email must be a valid email address.');
    });

    it('shows a rejected schedule at the schedule field', async () => {
        const wrapper = mountPage();

        wrapper.vm.scheduledAt = '2020-01-01T10:00';
        await wrapper.vm.$nextTick();

        press(wrapper, 'Schedule');
        await reject(wrapper, lastCall(), { scheduled_at: 'The scheduled at must be a date after now.' });

        expect(errorAtField(wrapper, 'Schedule for later')).toBe('The scheduled at must be a date after now.');
    });

    it('keeps showing the summary on a campaign that can no longer be edited', async () => {
        // `editable: false` hides the whole form. The summary must sit outside
        // it, or a refused send on a locked campaign says nothing.
        const wrapper = mountPage({ editable: false });

        wrapper.vm.sendNow();
        await reject(wrapper, lastCall(), { send: 'This campaign is already sending.' });

        expect(summary(wrapper)?.text()).toContain('This campaign is already sending.');
    });
});

describe('a key whose field is not on screen', () => {
    // The handle input exists only while creating. Both forms list `handle` in
    // `fieldKeys`, which is what keeps it out of the summary — correct on the
    // create page, where the field is there to receive it, and the reason the
    // message was rendered nowhere on an update. The controllers validate
    // `handle` through the same shared validator on store and update, so the
    // rejection is one changed payload away from being real.
    it('renders a rejected campaign handle on the create page at its field', async () => {
        const wrapper = mount(CampaignsEdit, {
            props: {
                campaign: null, storeUrl: '/cp/marketing/campaigns', updateUrl: null,
                deleteUrl: null, sendUrl: null, scheduleUrl: null, unscheduleUrl: null,
                testUrl: null, previewUrl: null, showUrl: null,
                lists: [], segments: [], templates: [], editable: true, canSend: false,
            },
        });

        wrapper.vm.name = 'March';
        await wrapper.vm.$nextTick();

        press(wrapper, 'Save');
        await reject(wrapper, lastCall(), { handle: 'That handle is already taken.' });

        expect(errorAtField(wrapper, 'Handle')).toBe('That handle is already taken.');
        expect(summary(wrapper), 'shown twice').toBeNull();
    });

    it('renders a rejected campaign handle on the edit page, where there is no handle field', async () => {
        const wrapper = mount(CampaignsEdit, {
            props: {
                campaign: {
                    handle: 'march', name: 'March', subject: null, preheader: null,
                    from_name: null, from_email: null, reply_to: null,
                    list: null, segment: '', template: '', content: '',
                    status: 'draft', scheduled_at: null, sent_at: null,
                },
                storeUrl: null, updateUrl: '/cp/marketing/campaigns/march',
                deleteUrl: null, sendUrl: null, scheduleUrl: null, unscheduleUrl: null,
                testUrl: null, previewUrl: null, showUrl: '/cp/marketing/campaigns/march',
                lists: [], segments: [], templates: [], editable: true, canSend: false,
            },
        });

        press(wrapper, 'Save');
        await reject(wrapper, lastCall(), { handle: 'That handle is already taken.' });

        expect(errorAtField(wrapper, 'Handle'), 'there is no handle field on an update').toBeUndefined();
        expect(visibleText(wrapper)).toContain('That handle is already taken.');
    });

    it('renders a rejected template handle on the edit page, where there is no handle field', async () => {
        const wrapper = mount(TemplatesEdit, {
            props: {
                template: { handle: 'layout', name: 'Layout', html: '<html></html>' },
                storeUrl: null,
                updateUrl: '/cp/marketing/templates/layout',
                deleteUrl: null,
                starterHtml: '<html>starter</html>',
            },
        });

        press(wrapper, 'Save');
        await reject(wrapper, lastCall(), { handle: 'That handle is already taken.' });

        expect(errorAtField(wrapper, 'Handle')).toBeUndefined();
        expect(visibleText(wrapper)).toContain('That handle is already taken.');
    });

    it('renders a rejected list handle on the edit page, where there is no handle field', async () => {
        const wrapper = mount(ListsEdit, {
            props: {
                list: { handle: 'newsletter', name: 'Newsletter', description: null, double_opt_in: null },
                storeUrl: null,
                updateUrl: '/cp/marketing/lists/newsletter',
                deleteUrl: null,
                defaultDoubleOptIn: true,
            },
        });

        press(wrapper, 'Save');
        await reject(wrapper, lastCall(), { handle: 'That handle is already taken.' });

        expect(errorAtField(wrapper, 'Handle')).toBeUndefined();
        expect(visibleText(wrapper)).toContain('That handle is already taken.');
    });
});
