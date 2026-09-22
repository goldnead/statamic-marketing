import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import CampaignsEdit from '../../resources/js/pages/Campaigns/Edit.vue';
import TemplatesEdit from '../../resources/js/pages/Templates/Edit.vue';

/**
 * How the preview is presented — not what it renders.
 *
 * The rendering has been right since 2.15.0: the same CampaignRenderer the real
 * send uses, refreshed while somebody types. What was wrong was everything
 * around it. The panel sat at the bottom of the form column, below the content
 * field, and it was closed. Opening a campaign therefore showed no preview at
 * all, and getting one meant scrolling past the editor and clicking. A live
 * preview that has to be asked for is a button that renders a page.
 *
 * These are the three properties that make it a preview again: it is open when
 * the screen opens, closing it is remembered, and the switches say what the
 * recipient's device is doing — including its theme, which is the one thing a
 * sender can otherwise only discover from somebody else's inbox.
 */

function campaignProps(overrides = {}) {
    return {
        campaign: {
            handle: 'welcome', name: 'Welcome', subject: 'Hi', preheader: null,
            from_name: null, from_email: null, reply_to: null, list: 'newsletter',
            segment: '', template: 'branded', content: '', status: 'draft',
            scheduled_at: null, sent_at: null,
        },
        storeUrl: null,
        updateUrl: '/cp/marketing/campaigns/welcome',
        deleteUrl: null,
        sendUrl: '/x',
        scheduleUrl: '/x',
        unscheduleUrl: '/x',
        testUrl: '/x',
        previewUrl: '/cp/marketing/campaigns/welcome/preview',
        livePreviewUrl: '/cp/marketing/campaigns/welcome/live-preview',
        showUrl: '/x',
        lists: [{ value: 'newsletter', label: 'Newsletter' }],
        segments: [],
        layouts: [{ value: 'branded', label: 'Branded', has_content_hole: true }],
        readyMades: [],
        editable: true,
        canSend: true,
        ...overrides,
    };
}

function campaignEditor(overrides = {}) {
    return mount(CampaignsEdit, { props: campaignProps(overrides) });
}

beforeEach(() => {
    // Every test starts from "this editor has never expressed a preference",
    // or the remembered-state test would leak into the default-state one.
    window.localStorage.clear();

    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ data: { html: '<p>rendered</p>', error: null } }),
    });

    window.Statamic = { ...(window.Statamic ?? {}), $config: { get: () => 'token' } };
});

afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('the campaign preview, as it is presented', () => {
    it('is open, and filled, before anybody has typed', async () => {
        const wrapper = campaignEditor();
        await flushPromises();

        expect(wrapper.vm.showPreview).toBe(true);
        expect(global.fetch).toHaveBeenCalledWith(
            '/cp/marketing/campaigns/welcome/live-preview',
            expect.anything(),
        );
        expect(wrapper.find('iframe').attributes('srcdoc')).toBe('<p>rendered</p>');
    });

    it('remembers that this editor closed it', async () => {
        const first = campaignEditor();
        await flushPromises();

        // The closing is driven through the state the button sets rather than
        // through a DOM click: the CP's Button is a stub here (tests/js/setup.js)
        // and never emits one. That the button is on screen and wired to this
        // state is asserted separately.
        expect(first.find('[data-marketing-campaign-preview-toggle]').exists()).toBe(true);

        first.vm.showPreview = false;
        await first.vm.$nextTick();

        expect(first.find('iframe').exists()).toBe(false);

        // A different campaign, a fresh component — the same person.
        const second = campaignEditor({ campaign: { ...campaignProps().campaign, handle: 'other' } });
        await flushPromises();

        expect(second.vm.showPreview).toBe(false);
    });

    it('keeps working when the browser refuses to store the preference', async () => {
        // Private windows and blocked site data make the accessor itself throw.
        // An unguarded read kills the page component before it renders — the
        // preference is not the thing worth losing the screen over.
        const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('denied');
        });
        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('denied');
        });

        const wrapper = campaignEditor();
        await flushPromises();

        expect(wrapper.vm.showPreview).toBe(true);
        expect(wrapper.find('iframe').exists()).toBe(true);

        wrapper.vm.showPreview = false;
        await wrapper.vm.$nextTick();

        expect(wrapper.find('iframe').exists()).toBe(false);

        getItem.mockRestore();
        setItem.mockRestore();
    });

    it('does not hold a column open for a preview that does not exist', async () => {
        // On `create` the controller sends no preview URL — there is no saved
        // campaign to render. Without this the form would keep its three fifths
        // and two fifths of nothing would sit beside it.
        const wrapper = mount(CampaignsEdit, {
            props: {
                ...campaignProps(),
                campaign: null,
                updateUrl: null,
                storeUrl: '/cp/marketing/campaigns',
                previewUrl: null,
                livePreviewUrl: null,
            },
        });
        await flushPromises();

        expect(wrapper.find('iframe').exists()).toBe(false);
        expect(wrapper.vm.previewIsSplit).toBe(false);
        expect(wrapper.html()).not.toContain('2xl:grid-cols-5');
    });

    it('narrows the frame to a phone', async () => {
        const wrapper = campaignEditor();
        await flushPromises();

        const frame = () => wrapper.find('iframe').element.parentElement.className;

        expect(frame()).toContain('max-w-full');

        wrapper.vm.previewWidth = 'mobile';
        await wrapper.vm.$nextTick();

        expect(frame()).toContain('max-w-[390px]');
    });
});

/**
 * The dark switch.
 *
 * What is being asked is not "is the Control Panel dark" — the preview canvas
 * deliberately ignores that, see resources/css/cp.css. It is "what does a phone
 * set to dark do with this mail": the class carries `color-scheme: dark`, which
 * is what makes `prefers-color-scheme: dark` answer true inside the frame.
 */
describe.each([
    ['campaign', () => campaignEditor(), 'data-marketing-campaign-preview-scheme'],
    ['layout', () => mount(TemplatesEdit, {
        props: {
            template: { handle: 'branded', name: 'Branded', html: '<div>{{ content }}</div>' },
            storeUrl: null,
            updateUrl: '/cp/marketing/templates/branded',
            deleteUrl: null,
            starterHtml: '',
            previewUrl: '/cp/marketing/templates/preview',
        },
    }), 'data-marketing-template-preview-scheme'],
])('the %s editor', (_name, editor, schemeSwitch) => {
    it('offers the switch and starts light', async () => {
        const wrapper = editor();
        await flushPromises();

        expect(wrapper.find(`[${schemeSwitch}]`).exists()).toBe(true);
        expect(wrapper.find('iframe').classes()).not.toContain('marketing-email-canvas--dark');
    });

    it('puts the dark canvas on the frame and on the paper behind it', async () => {
        const wrapper = editor();
        await flushPromises();

        wrapper.vm.previewScheme = 'dark';
        await wrapper.vm.$nextTick();

        const frame = wrapper.find('iframe');

        expect(frame.classes()).toContain('marketing-email-canvas--dark');
        expect(frame.element.parentElement.className).toContain('marketing-email-canvas--dark');
    });
});
