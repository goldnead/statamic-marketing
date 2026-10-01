import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CampaignsShow from '../../resources/js/pages/Campaigns/Show.vue';
import CampaignsIndex from '../../resources/js/pages/Campaigns/Index.vue';
import { router } from '@statamic/cms/inertia';
import { captureRouter, press, reject, summary } from './helpers.js';

/**
 * The approval of a series child, as a screen: the button is there only
 * where it means something, it asks before it sends, it posts to the
 * approval route, and a refusal lands where the page shows every other
 * send error. Plus the listing's tabs, which narrow the rows client-side.
 */

const APPROVAL = {
    subject: 'Wir spielen in Ulm',
    from_name: 'Halbmond',
    from_email: 'tour@example.com',
    list: 'Tourmail',
    segment: 'Konzert: Ulm 89073 (50 km)',
    recipients: 4,
    event: { title: 'Tiefdruck', city: 'Ulm', venue: 'Roxy', date: '20.10.2026', time: '20:00' },
    scheduled_at: '2099-10-13T08:00:00+00:00',
    template: { name: 'Konzert in deiner Nähe!', edit_url: '/t' },
    preview_url: '/preview',
    approve_url: '/approve',
    withdraw_url: '/withdraw',
    can_send: true,
};

function showProps(status, approval = APPROVAL) {
    return {
        campaign: { handle: 'kind', name: 'Konzert (Ulm)', subject: 'x', status },
        tab: 'overview',
        tabs: [{ name: 'overview', label: 'Übersicht' }],
        stats: null, humanOpens: null, timeline: null, activity: null,
        rows: [], columns: [], pagination: null,
        statuses: [], filters: { status: null },
        urlBreakdown: null, exportUrl: null,
        editUrl: '/edit', editable: false,
        archive: { enabled: false },
        canManage: true,
        mailPreviewUrl: null,
        statusLabel: status,
        approval,
    };
}

describe('approving a series child', () => {
    let calls;

    beforeEach(() => { calls = captureRouter(); });
    afterEach(() => { vi.restoreAllMocks(); });

    it('asks first, then posts to the approval route', async () => {
        const wrapper = mount(CampaignsShow, { props: showProps('awaiting_approval') });

        press(wrapper, 'marketing::series.approve');
        await wrapper.vm.$nextTick();

        expect(calls).toHaveLength(0);

        const modal = wrapper.findAllComponents({ name: 'ConfirmationModal' })
            .find((candidate) => candidate.attributes('data-attr-title') === 'marketing::series.approve_confirm_title');

        expect(modal.attributes('data-attr-open')).toBe('true');

        modal.vm.$attrs.onConfirm();

        expect(calls.map((call) => [call.verb, call.url])).toEqual([['post', '/approve']]);
    });

    it('shows a refused approval with the other send errors', async () => {
        const wrapper = mount(CampaignsShow, { props: showProps('awaiting_approval') });

        press(wrapper, 'marketing::series.approve');
        wrapper.findAllComponents({ name: 'ConfirmationModal' })
            .find((candidate) => candidate.attributes('data-attr-title') === 'marketing::series.approve_confirm_title')
            .vm.$attrs.onConfirm();

        await reject(wrapper, calls[0], { send: 'The term has started.' });

        expect(summary(wrapper).text()).toContain('The term has started.');
    });

    it('sends a test of this child to the editor', () => {
        const wrapper = mount(CampaignsShow, {
            props: showProps('awaiting_approval', { ...APPROVAL, test_url: '/test', test_email: 'ich@example.com' }),
        });

        press(wrapper, 'marketing::series.test_send');

        expect(calls.map((call) => [call.verb, call.url])).toEqual([['post', '/test']]);
        expect(router.post.mock.calls[0][1]).toEqual({ email: 'ich@example.com' });
    });

    it('says, before the button, that nothing goes out without it', () => {
        const wrapper = mount(CampaignsShow, { props: showProps('awaiting_approval') });

        expect(wrapper.find('[data-marketing-approval-note]').text()).toContain('marketing::series.approval_note');
        expect(wrapper.find('[data-marketing-approval-row="preheader"]').exists()).toBe(true);
    });

    it('offers withdraw, not approve, once it is scheduled', () => {
        const wrapper = mount(CampaignsShow, { props: showProps('scheduled') });

        expect(() => press(wrapper, 'marketing::series.approve')).toThrow();

        press(wrapper, 'marketing::series.withdraw');

        expect(calls.map((call) => [call.verb, call.url])).toEqual([['post', '/withdraw']]);
    });

    it('offers neither without the send permission', () => {
        const wrapper = mount(CampaignsShow, { props: showProps('awaiting_approval', { ...APPROVAL, can_send: false }) });

        expect(() => press(wrapper, 'marketing::series.approve')).toThrow();
        expect(wrapper.find('[data-marketing-approval]').exists()).toBe(true);
    });

    it('shows the plan, not a report of zeros, before the send has started', () => {
        const props = {
            ...showProps('scheduled', null),
            sendingStarted: false,
            audienceEstimate: 3,
            timeline: [{ key: 'scheduled', at: '2099-01-01T10:00:00Z' }],
        };
        const wrapper = mount(CampaignsShow, { props });

        expect(wrapper.findComponent({ name: 'Tabs' }).exists()).toBe(false);
        expect(wrapper.find('[data-marketing-not-started]').exists()).toBe(true);
        expect(wrapper.find('[data-marketing-audience-estimate]').text()).toContain('marketing::campaigns.audience_estimate_many');
    });

    it('keeps the report once the send has started', () => {
        const wrapper = mount(CampaignsShow, { props: { ...showProps('sending', null), sendingStarted: true } });

        expect(wrapper.findComponent({ name: 'Tabs' }).exists()).toBe(true);
        expect(wrapper.find('[data-marketing-not-started]').exists()).toBe(false);
    });

    it('has no approval at all on an ordinary campaign', () => {
        const wrapper = mount(CampaignsShow, { props: showProps('draft', null) });

        expect(wrapper.find('[data-marketing-approval]').exists()).toBe(false);
    });
});

describe('the campaign tabs', () => {
    const campaigns = [
        { id: 'a', handle: 'a', name: 'A', status: 'series' },
        { id: 'b', handle: 'b', name: 'B', status: 'awaiting_approval', series: 'a' },
        { id: 'c', handle: 'c', name: 'C', status: 'draft' },
    ];

    const tabs = [
        { name: 'all', label: 'Alle', count: 3 },
        { name: 'awaiting_approval', label: 'Wartet', count: 1 },
        { name: 'series', label: 'Serien', count: 1 },
    ];

    it('narrows the listing to the tab', async () => {
        const wrapper = mount(CampaignsIndex, { props: { campaigns, columns: [], createUrl: '/c', canManage: true, tabs } });
        const listing = () => wrapper.findComponent({ name: 'Listing' });

        expect(listing().vm.$attrs.items).toHaveLength(3);

        wrapper.findComponent({ name: 'Tabs' }).vm.$emit('update:modelValue', 'awaiting_approval');
        await wrapper.vm.$nextTick();

        expect(listing().vm.$attrs.items.map((row) => row.handle)).toEqual(['b']);
        expect(window.location.search).toContain('status=awaiting_approval');
    });

    it('queues the waiting tab by send time, immediate ones first', async () => {
        const waiting = [
            { id: 'late', handle: 'late', status: 'awaiting_approval', scheduled_at: '2099-02-01T10:00:00Z' },
            { id: 'now', handle: 'now', status: 'awaiting_approval', scheduled_at: null },
            { id: 'soon', handle: 'soon', status: 'awaiting_approval', scheduled_at: '2099-01-01T10:00:00Z' },
        ];
        const wrapper = mount(CampaignsIndex, { props: { campaigns: waiting, columns: [], createUrl: '/c', canManage: true, tabs } });

        wrapper.findComponent({ name: 'Tabs' }).vm.$emit('update:modelValue', 'awaiting_approval');
        await wrapper.vm.$nextTick();

        const listing = wrapper.findComponent({ name: 'Listing' });

        expect(listing.vm.$attrs.items.map((row) => row.handle)).toEqual(['now', 'soon', 'late']);
        expect(listing.vm.$attrs.sortable).toBe(false);
    });
});
