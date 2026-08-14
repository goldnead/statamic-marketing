import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CampaignsShow from '../../resources/js/pages/Campaigns/Show.vue';
import { captureRouter } from './helpers.js';

/**
 * The campaign report, as a screen.
 *
 * The Pest suite proves the server hands over the right rows. What it cannot
 * see is the half a reader actually meets: whether the tabs are the Control
 * Panel's own tabs or a row of buttons somebody drew, whether switching one
 * asks the server for that tab or silently shows the previous one's data,
 * whether the export carries the filter the reader was looking at, and whether
 * the sentence explaining a prefetched open is on the screen where the
 * prefetched opens are counted.
 *
 * The stubs in tests/js/setup.js render every component as a div carrying its
 * scalar props. That covers everything above. It does not cover the cells of a
 * `Listing`, which are named slots the stub never calls — the contact link and
 * the prefetch badge are therefore asserted server-side, in
 * tests/Feature/CampaignReportTest.php.
 */

const TABS = [
    { name: 'overview', label: 'Übersicht' },
    { name: 'delivery', label: 'Zustellbarkeit' },
    { name: 'opens', label: 'Öffnungen' },
    { name: 'clicks', label: 'Klicks' },
    { name: 'unsubscribes', label: 'Abmeldungen' },
];

const CAMPAIGN = { handle: 'brief', name: 'Der Brief', subject: 'Hallo', status: 'sent', sent_at: '2026-08-01T09:00:00+00:00' };

const ARCHIVE = { enabled: false, released: false, live: false, sendable_only: false, url: '/a', update_url: '/u' };

function props(overrides = {}) {
    return {
        campaign: CAMPAIGN,
        tab: 'overview',
        tabs: TABS,
        stats: null,
        humanOpens: null,
        timeline: null,
        rows: [],
        columns: [],
        pagination: null,
        statuses: [{ value: '', label: 'Alle Status' }, { value: 'failed', label: 'Fehlgeschlagen' }],
        filters: { status: null },
        urlBreakdown: null,
        exportUrl: null,
        editUrl: '/edit',
        editable: false,
        archive: ARCHIVE,
        canManage: true,
        ...overrides,
    };
}

function overviewProps(overrides = {}) {
    return props({
        tab: 'overview',
        stats: {
            recipients: 120, sent: 118, failed: 2, skipped: 0, capped: 0, pending: 0,
            opened: 60, open_rate: 50.8, clicked: 12, click_rate: 10.2,
            bounced: 1, unsubscribed: 3, variants: {},
        },
        humanOpens: { human: 40, machine_only: 20, human_rate: 33.9 },
        timeline: [
            { key: 'started', at: '2026-08-01T09:00:00+00:00' },
            { key: 'sent', at: '2026-08-01T09:05:00+00:00' },
        ],
        ...overrides,
    });
}

beforeEach(() => {
    captureRouter.calls = captureRouter();
});

afterEach(() => {
    vi.restoreAllMocks();
});

function calls() {
    return captureRouter.calls;
}

/** The Tabs component's controlled update, invoked the way a click would. */
function switchTo(wrapper, tab) {
    const tabs = wrapper.findComponent({ name: 'Tabs' });

    expect(tabs.exists(), 'the report is not using the Control Panel tabs').toBe(true);

    tabs.vm.$attrs['onUpdate:modelValue'](tab);
}

describe('Campaigns/Show — tabs', () => {
    it('uses the Control Panel tab components rather than a row of buttons', () => {
        const wrapper = mount(CampaignsShow, { props: overviewProps() });

        expect(wrapper.findComponent({ name: 'Tabs' }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: 'TabList' }).exists()).toBe(true);
        expect(wrapper.findAllComponents({ name: 'TabTrigger' })).toHaveLength(5);
    });

    it('names every trigger after a content pane', () => {
        // `name` is the whole contract between the two halves: a trigger whose
        // name matches no content opens an empty tab.
        const wrapper = mount(CampaignsShow, { props: overviewProps() });

        const triggers = wrapper.findAllComponents({ name: 'TabTrigger' })
            .map((t) => t.attributes('data-attr-name'));
        const contents = wrapper.findAllComponents({ name: 'TabContent' })
            .map((c) => c.attributes('data-attr-name'));

        expect(triggers).toEqual(['overview', 'delivery', 'opens', 'clicks', 'unsubscribes']);
        expect(triggers.every((name) => contents.includes(name))).toBe(true);
    });

    it('asks the server for the tab that was chosen', () => {
        // Each tab is a different query over a table that can hold fifty
        // thousand rows per campaign, so a tab is a request, not a class change.
        const wrapper = mount(CampaignsShow, { props: overviewProps() });

        switchTo(wrapper, 'clicks');

        expect(calls()).toHaveLength(1);
        expect(calls()[0].verb).toBe('get');
    });

    it('does not ask again for the tab that is already open', () => {
        const wrapper = mount(CampaignsShow, { props: overviewProps() });

        switchTo(wrapper, 'overview');

        expect(calls()).toHaveLength(0);
    });

    it('leaves the delivery status filter behind when moving to another tab', () => {
        // A status is a property of a message. Carrying "failed" into the
        // clicks tab would filter it by a column that tab does not have.
        const wrapper = mount(CampaignsShow, {
            props: props({ tab: 'delivery', filters: { status: 'failed' }, rows: [] }),
        });

        switchTo(wrapper, 'opens');

        expect(calls()[0].options.preserveState).toBe(true);
    });
});

describe('Campaigns/Show — the figures', () => {
    it('shows the unchanged campaign figures and the human opens beside them', () => {
        const wrapper = mount(CampaignsShow, { props: overviewProps() });
        const text = wrapper.text();

        // The old number, still counting everything.
        expect(text).toContain('50.8%');
        // The new one, named, not replacing it.
        expect(text).toContain('40');
        expect(text).toContain('33.9%');
    });

    it('explains a prefetched open where the open figures are read', () => {
        const wrapper = mount(CampaignsShow, { props: overviewProps() });

        expect(wrapper.find('[data-marketing-prefetch-note]').exists()).toBe(true);
        expect(wrapper.text()).toContain('marketing::timeline.prefetched_note');
    });

    it('repeats that explanation on the opens tab, where it is read per person', () => {
        const wrapper = mount(CampaignsShow, {
            props: props({ tab: 'opens', rows: [{ email: 'a@example.com', opens: 2, human_opens: 1, machine_opens: 1 }] }),
        });

        expect(wrapper.find('[data-marketing-prefetch-note]').exists()).toBe(true);
    });

    it('repeats it on delivery too, which carries an opens column of its own', () => {
        const wrapper = mount(CampaignsShow, {
            props: props({ tab: 'delivery', rows: [{ email: 'a@example.com', status: 'sent', opens: 2, clicks: 0 }] }),
        });

        expect(wrapper.find('[data-marketing-prefetch-note]').exists()).toBe(true);
    });

    it('leaves the explanation out where there are no figures to explain', () => {
        // On an empty tab it explains numbers that are not on the screen, and
        // sat under a "no results" box as a second paragraph of grey text.
        const wrapper = mount(CampaignsShow, { props: props({ tab: 'opens', rows: [] }) });

        expect(wrapper.find('[data-marketing-prefetch-note]').exists()).toBe(false);
    });

    it('hides the machine-only tile when there is none', () => {
        const wrapper = mount(CampaignsShow, {
            props: overviewProps({ humanOpens: { human: 60, machine_only: 0, human_rate: 50.8 } }),
        });

        expect(wrapper.text()).not.toContain('marketing::campaigns.report.opens_machine_only');
    });

    it('renders a campaign nobody has received without a NaN in sight', () => {
        const wrapper = mount(CampaignsShow, {
            props: overviewProps({
                stats: {
                    recipients: 0, sent: 0, failed: 0, skipped: 0, capped: 0, pending: 0,
                    opened: 0, open_rate: 0, clicked: 0, click_rate: 0,
                    bounced: 0, unsubscribed: 0, variants: {},
                },
                humanOpens: { human: 0, machine_only: 0, human_rate: 0 },
                timeline: [],
            }),
        });

        expect(wrapper.text()).not.toContain('NaN');
        expect(wrapper.text()).not.toContain('undefined');
    });
});

describe('Campaigns/Show — the timeline', () => {
    it('shows only the stations that happened', () => {
        const wrapper = mount(CampaignsShow, { props: overviewProps() });
        const text = wrapper.text();

        expect(text).toContain('marketing::campaigns.report.timeline.started');
        expect(text).toContain('marketing::campaigns.report.timeline.sent');
        // Never scheduled, never opened — and therefore no row saying so.
        expect(text).not.toContain('marketing::campaigns.report.timeline.scheduled');
        expect(text).not.toContain('marketing::campaigns.report.timeline.first_open');
    });

    it('shows no timeline block at all when nothing has happened', () => {
        const wrapper = mount(CampaignsShow, { props: overviewProps({ timeline: [] }) });

        expect(wrapper.text()).not.toContain('marketing::campaigns.report.timeline_heading');
    });
});

describe('Campaigns/Show — the export', () => {
    function deliveryWithExport(overrides = {}) {
        return props({
            tab: 'delivery',
            rows: [{ id: '1', email: 'maria@example.com', name: 'Maria', status: 'sent', contact_url: null }],
            columns: [{ field: 'email', label: 'E-Mail' }],
            pagination: { current_page: 1, last_page: 1, total: 1 },
            exportUrl: '/cp/marketing/campaigns/brief/export',
            ...overrides,
        });
    }

    it('exports the tab that is open', () => {
        const wrapper = mount(CampaignsShow, { props: deliveryWithExport() });
        const button = wrapper.find('[data-marketing-export]');

        expect(button.exists()).toBe(true);
        expect(button.attributes('data-attr-href')).toBe('/cp/marketing/campaigns/brief/export?tab=delivery');
    });

    it('exports the filter the reader is looking at, not the whole campaign', () => {
        const wrapper = mount(CampaignsShow, {
            props: deliveryWithExport({ filters: { status: 'failed' } }),
        });

        expect(wrapper.find('[data-marketing-export]').attributes('data-attr-href'))
            .toBe('/cp/marketing/campaigns/brief/export?tab=delivery&status=failed');
    });

    it('offers nothing to export on the overview', () => {
        const wrapper = mount(CampaignsShow, { props: overviewProps({ exportUrl: '/x' }) });

        expect(wrapper.find('[data-marketing-export]').exists()).toBe(false);
    });

    it('offers no export button to a reader who was given no URL', () => {
        const wrapper = mount(CampaignsShow, { props: deliveryWithExport({ exportUrl: null }) });

        expect(wrapper.find('[data-marketing-export]').exists()).toBe(false);
    });
});

describe('Campaigns/Show — the link breakdown', () => {
    it('says which link was followed, how often, and by how many people', () => {
        const wrapper = mount(CampaignsShow, {
            props: props({
                tab: 'clicks',
                rows: [],
                columns: [],
                pagination: { current_page: 1, last_page: 1, total: 0 },
                urlBreakdown: [{ url: 'https://example.com/kurs', clicks: 3, people: 2 }],
            }),
        });

        const text = wrapper.text();

        expect(text).toContain('https://example.com/kurs');
        expect(text).toContain('3');
        expect(text).toContain('2');
    });

    it('shows no breakdown on a tab that is not about links', () => {
        const wrapper = mount(CampaignsShow, {
            props: props({ tab: 'opens', rows: [], pagination: { current_page: 1, last_page: 1, total: 0 } }),
        });

        expect(wrapper.text()).not.toContain('marketing::campaigns.report.urls_heading');
    });
});

describe('Campaigns/Show — pagination', () => {
    it('keeps the tab and the filter in the page it asks for', () => {
        const wrapper = mount(CampaignsShow, {
            props: props({
                tab: 'delivery',
                filters: { status: 'failed' },
                rows: [],
                pagination: { current_page: 1, last_page: 4, total: 200 },
            }),
        });

        const next = wrapper.findAllComponents({ name: 'Button' })
            .find((candidate) => candidate.attributes('data-attr-text') === 'Next');

        expect(next, 'the pager is missing').toBeTruthy();

        next.vm.$attrs.onClick();

        expect(calls()).toHaveLength(1);
        expect(calls()[0].options.preserveScroll).toBe(true);
    });

    it('shows no pager on a single page', () => {
        const wrapper = mount(CampaignsShow, {
            props: props({
                tab: 'delivery',
                rows: [],
                pagination: { current_page: 1, last_page: 1, total: 3 },
            }),
        });

        const next = wrapper.findAllComponents({ name: 'Button' })
            .find((candidate) => candidate.attributes('data-attr-text') === 'Next');

        expect(next).toBeFalsy();
    });
});
