import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Dashboard from '../../resources/js/pages/Dashboard.vue';
import CampaignsShow from '../../resources/js/pages/Campaigns/Show.vue';
import BarChart from '../../resources/js/components/BarChart.vue';

/**
 * The three charts, as a reader meets them.
 *
 * The Pest suite proves the server counts the right things. What it cannot see
 * is the half that decides whether the picture tells the truth:
 *
 *  - whether the machine share is still a band of its own by the time it is
 *    drawn, or has been quietly added into the opens somewhere between the
 *    controller and the bar,
 *  - whether a campaign nobody opened draws an empty axis (which reads as a
 *    broken chart) or says so in words,
 *  - whether a single sent campaign is presented as a trend,
 *  - and whether a week with nothing in it survives as a column.
 *
 * `__()` returns its key in this process (tests/js/setup.js), so the assertions
 * name the translation keys rather than German sentences — which is also how a
 * renamed key fails here instead of silently rendering nothing.
 */

const ARCHIVE = { enabled: false, released: false, live: false, sendable_only: false, url: '/a', update_url: '/u' };

const TABS = [
    { name: 'overview', label: 'Übersicht' },
    { name: 'opens', label: 'Öffnungen' },
];

function reportProps(activity) {
    return {
        campaign: { handle: 'brief', name: 'Der Brief', subject: 'Hallo', status: 'sent', sent_at: '2026-08-14T09:00:00+00:00' },
        tab: 'overview',
        tabs: TABS,
        stats: {
            recipients: 10, sent: 10, failed: 0, skipped: 0, capped: 0, pending: 0,
            opened: 3, open_rate: 30.0, clicked: 1, click_rate: 10.0,
            bounced: 0, unsubscribed: 0, variants: {},
        },
        humanOpens: { human: 1, machine_only: 2, human_rate: 10.0 },
        activity,
        timeline: [],
        rows: [],
        columns: [],
        pagination: null,
        statuses: [],
        filters: { status: null },
        urlBreakdown: null,
        exportUrl: null,
        editUrl: '/edit',
        editable: false,
        archive: ARCHIVE,
        canManage: true,
    };
}

function dashboardProps(overrides = {}) {
    return {
        totalSubscribed: 0,
        totalPending: 0,
        listCount: 0,
        lists: [],
        recentCampaigns: [],
        engagement: [],
        growth: { weeks: [], totals: { subscribed: 0, unsubscribed: 0, net: 0 } },
        createCampaignUrl: '/create-campaign',
        createListUrl: '/create-list',
        ...overrides,
    };
}

/** Twelve weeks, all quiet unless named. */
function weeks(filled = {}) {
    return Array.from({ length: 12 }, (_, index) => {
        const at = `2026-0${index < 4 ? 6 : 7}-${String(1 + index * 2).padStart(2, '0')}T00:00:00+00:00`;
        const week = filled[index] || {};

        return {
            at,
            subscribed: week.subscribed ?? 0,
            unsubscribed: week.unsubscribed ?? 0,
            net: (week.subscribed ?? 0) - (week.unsubscribed ?? 0),
        };
    });
}

describe('the activity curve on a campaign report', () => {
    const busy = {
        unit: 'hour',
        buckets: [
            { at: '2026-08-14T09:00:00+00:00', human_opens: 0, machine_opens: 7, clicks: 0 },
            { at: '2026-08-14T10:00:00+00:00', human_opens: 0, machine_opens: 0, clicks: 0 },
            { at: '2026-08-14T11:00:00+00:00', human_opens: 2, machine_opens: 0, clicks: 1 },
        ],
        totals: { human_opens: 2, machine_opens: 7, clicks: 1 },
        beyond: 0,
    };

    it('keeps the machine share as a band of its own', () => {
        const wrapper = mount(CampaignsShow, { props: reportProps(busy) });
        const chart = wrapper.findComponent(BarChart);

        expect(chart.exists()).toBe(true);

        const first = chart.props('groups')[0];

        // The first hour is seven machine opens and no person — the shape MPP
        // produces. Summed anywhere on the way here it would be a seven-open
        // spike that reads as "seven people opened it straight away".
        expect(first.stacks[0]).toEqual([
            { series: 'human_opens', value: 0 },
            { series: 'machine_opens', value: 7 },
        ]);

        // And the three are three separate series, so the legend can name them.
        expect(chart.props('series').map((s) => s.key))
            .toEqual(['human_opens', 'machine_opens', 'clicks']);
    });

    it('says what each column contains, for anybody who cannot see it', () => {
        const wrapper = mount(CampaignsShow, { props: reportProps(busy) });
        const columns = wrapper.findAll('[role="listitem"]');

        expect(columns).toHaveLength(3);
        expect(columns[0].attributes('aria-label')).toContain('marketing::campaigns.report.activity_bucket');
        expect(wrapper.find('.sr-only').text()).toContain('marketing::campaigns.report.activity_summary');
    });

    it('carries a quiet hour as an empty column rather than dropping it', () => {
        const wrapper = mount(CampaignsShow, { props: reportProps(busy) });

        // Three buckets in, three columns out. Dropped, the 09:00 spike would
        // sit directly beside the 11:00 one and the two-hour gap would vanish.
        expect(wrapper.findComponent(BarChart).props('groups')).toHaveLength(3);
    });

    it('says so in words when nothing has been opened yet', () => {
        const wrapper = mount(CampaignsShow, {
            props: reportProps({
                unit: 'hour',
                buckets: [],
                totals: { human_opens: 0, machine_opens: 0, clicks: 0 },
                beyond: 0,
            }),
        });

        expect(wrapper.find('[data-marketing-activity-empty]').exists()).toBe(true);
        expect(wrapper.findComponent(BarChart).exists()).toBe(false);
        expect(wrapper.html()).not.toContain('NaN');
    });

    it('keeps the prefetch explanation on the same screen as the chart', () => {
        const wrapper = mount(CampaignsShow, { props: reportProps(busy) });

        expect(wrapper.find('[data-marketing-prefetch-note]').text())
            .toContain('marketing::timeline.prefetched_note');
    });

    it('mentions the events that fall past the end of the axis', () => {
        const wrapper = mount(CampaignsShow, { props: reportProps({ ...busy, beyond: 4 }) });

        expect(wrapper.text()).toContain('marketing::campaigns.report.activity_beyond');
    });

    it('gives the axis to the people and lets the proxy run off the top', () => {
        // The shape MPP produces, at the ratio it actually produces it: forty
        // fetches in one bucket against at most two of anything human. Scaled
        // to the tallest stack, every human bar here is a hairline and the
        // question "which hour was it read in" cannot be answered from the
        // picture drawn to answer it.
        const wrapper = mount(CampaignsShow, {
            props: reportProps({
                unit: 'hour',
                buckets: [
                    { at: '2026-08-14T09:00:00+00:00', human_opens: 0, machine_opens: 40, clicks: 0 },
                    { at: '2026-08-14T10:00:00+00:00', human_opens: 2, machine_opens: 1, clicks: 0 },
                    { at: '2026-08-14T11:00:00+00:00', human_opens: 1, machine_opens: 0, clicks: 1 },
                ],
                totals: { human_opens: 3, machine_opens: 41, clicks: 1 },
                beyond: 0,
            }),
        });

        // The ceiling is the tallest human bucket against the tallest click
        // bucket — never the sum with the machine share.
        expect(wrapper.findComponent(BarChart).props('max')).toBe(2);
    });

    it('names the preload peak in words once the bar can no longer show it', () => {
        const wrapper = mount(CampaignsShow, {
            props: reportProps({
                unit: 'hour',
                buckets: [{ at: '2026-08-14T09:00:00+00:00', human_opens: 1, machine_opens: 40, clicks: 0 }],
                totals: { human_opens: 1, machine_opens: 40, clicks: 0 },
                beyond: 0,
            }),
        });

        // A bar that ends at the ceiling has a true value the picture cannot
        // show, so the scale line says it.
        expect(wrapper.text()).toContain('marketing::campaigns.report.activity_scale_machine');
    });

    it('keeps the scale line quiet where nothing runs off the top', () => {
        const wrapper = mount(CampaignsShow, {
            props: reportProps({
                unit: 'hour',
                buckets: [{ at: '2026-08-14T09:00:00+00:00', human_opens: 5, machine_opens: 2, clicks: 0 }],
                totals: { human_opens: 5, machine_opens: 2, clicks: 0 },
                beyond: 0,
            }),
        });

        expect(wrapper.text()).not.toContain('marketing::campaigns.report.activity_scale_machine');
    });

    it('says that the bars are events and the tile above is messages', () => {
        const wrapper = mount(CampaignsShow, { props: reportProps(busy) });

        expect(wrapper.find('[data-marketing-activity-counts]').text())
            .toContain('marketing::campaigns.report.activity_counts_note');
    });

    it('warns where the campaign predates the detection, and only there', () => {
        const old = mount(CampaignsShow, {
            props: reportProps({ ...busy, predates_detection: true, detection_since: '2026-08-15' }),
        });
        const recent = mount(CampaignsShow, {
            props: reportProps({ ...busy, predates_detection: false, detection_since: '2026-08-15' }),
        });

        expect(old.find('[data-marketing-activity-predates]').exists()).toBe(true);
        expect(recent.find('[data-marketing-activity-predates]').exists()).toBe(false);
    });
});

describe('the dashboard charts', () => {
    it('renders on an install where nothing has happened at all', () => {
        const wrapper = mount(Dashboard, { props: dashboardProps() });

        expect(wrapper.find('[data-marketing-engagement-empty]').exists()).toBe(true);
        expect(wrapper.find('[data-marketing-growth-empty]').exists()).toBe(true);
        expect(wrapper.html()).not.toContain('NaN');
        expect(wrapper.html()).not.toContain('Infinity');
    });

    it('shows the first campaign but does not call it a trend', () => {
        const wrapper = mount(Dashboard, {
            props: dashboardProps({
                engagement: [{ handle: 'erste', name: 'Die erste', sent_at: '2026-08-05T09:00:00+00:00', url: '/c', open_rate: 42.5, click_rate: 4.1, sent: 120 }],
            }),
        });

        expect(wrapper.findComponent(BarChart).props('groups')).toHaveLength(1);
        expect(wrapper.find('[data-marketing-engagement-single]').exists()).toBe(true);
    });

    it('drops the "not a trend" note once there is something to compare', () => {
        const wrapper = mount(Dashboard, {
            props: dashboardProps({
                engagement: [
                    { handle: 'a', name: 'A', sent_at: '2026-08-01T09:00:00+00:00', url: '/a', open_rate: 40, click_rate: 4, sent: 100 },
                    { handle: 'b', name: 'B', sent_at: '2026-08-08T09:00:00+00:00', url: '/b', open_rate: 31, click_rate: 6, sent: 110 },
                ],
            }),
        });

        expect(wrapper.find('[data-marketing-engagement-single]').exists()).toBe(false);
        expect(wrapper.findComponent(BarChart).props('groups')).toHaveLength(2);
    });

    it('takes the rates it is given rather than working any out', () => {
        const wrapper = mount(Dashboard, {
            props: dashboardProps({
                engagement: [
                    { handle: 'a', name: 'A', sent_at: '2026-08-01T09:00:00+00:00', url: '/a', open_rate: 44.5, click_rate: 13.5, sent: 1103 },
                    { handle: 'b', name: 'B', sent_at: '2026-08-08T09:00:00+00:00', url: '/b', open_rate: 31.2, click_rate: 6.4, sent: 1110 },
                ],
            }),
        });

        const values = wrapper.findComponent(BarChart).props('groups')
            .flatMap((group) => group.stacks.flat().map((segment) => segment.value));

        expect(values).toEqual([44.5, 13.5, 31.2, 6.4]);
    });

    it('says the youngest bar is still filling up, and only while it is', () => {
        // A campaign sent yesterday stands next to campaigns that finished
        // collecting weeks ago, and its rate is systematically the lowest for a
        // reason that is not about the campaign. The bar stays — it is the real
        // figure — and the sentence says why it is short.
        const hoursAgo = (hours) => new Date(Date.now() - hours * 36e5).toISOString();

        const fresh = mount(Dashboard, {
            props: dashboardProps({
                engagement: [
                    { handle: 'a', name: 'A', sent_at: hoursAgo(24 * 30), url: '/a', open_rate: 40, click_rate: 4, sent: 100 },
                    { handle: 'b', name: 'B', sent_at: hoursAgo(6), url: '/b', open_rate: 11, click_rate: 1, sent: 110 },
                ],
            }),
        });

        const settled = mount(Dashboard, {
            props: dashboardProps({
                engagement: [
                    { handle: 'a', name: 'A', sent_at: hoursAgo(24 * 30), url: '/a', open_rate: 40, click_rate: 4, sent: 100 },
                    { handle: 'b', name: 'B', sent_at: hoursAgo(24 * 7), url: '/b', open_rate: 31, click_rate: 6, sent: 110 },
                ],
            }),
        });

        expect(fresh.find('[data-marketing-engagement-fresh]').exists()).toBe(true);
        expect(settled.find('[data-marketing-engagement-fresh]').exists()).toBe(false);
    });

    it('draws all twelve weeks, including the ones nobody joined', () => {
        const wrapper = mount(Dashboard, {
            props: dashboardProps({
                growth: {
                    weeks: weeks({ 0: { subscribed: 9 }, 11: { subscribed: 2, unsubscribed: 5 } }),
                    totals: { subscribed: 11, unsubscribed: 5, net: 6 },
                },
            }),
        });

        const charts = wrapper.findAllComponents(BarChart);
        const growth = charts[charts.length - 1];

        expect(growth.props('groups')).toHaveLength(12);
        // The ten quiet weeks between the first and the last are columns with
        // nothing in them, not columns that are missing.
        expect(growth.props('groups')[5].stacks.flat().map((s) => s.value)).toEqual([0, 0]);
        expect(wrapper.find('[data-marketing-growth-note]').exists()).toBe(true);
    });

    it('says nothing happened rather than drawing twelve empty tracks', () => {
        const wrapper = mount(Dashboard, {
            props: dashboardProps({
                growth: { weeks: weeks(), totals: { subscribed: 0, unsubscribed: 0, net: 0 } },
            }),
        });

        expect(wrapper.find('[data-marketing-growth-empty]').exists()).toBe(true);
    });
});

describe('the bars themselves', () => {
    const series = [
        { key: 'a', label: 'A', class: 'bg-green-500' },
        { key: 'b', label: 'B', class: 'bg-red-400 dark:bg-red-500' },
    ];

    it('scales the tallest stack to the full track', () => {
        const wrapper = mount(BarChart, {
            props: {
                label: 'Test',
                series,
                groups: [
                    { key: '1', xLabel: '01.', ariaLabel: 'eins', stacks: [[{ series: 'a', value: 5 }]] },
                    { key: '2', xLabel: '02.', ariaLabel: 'zwei', stacks: [[{ series: 'a', value: 10 }]] },
                ],
            },
        });

        const heights = wrapper.findAll('[role="listitem"] [style]')
            .map((node) => node.attributes('style'));

        expect(heights).toEqual(['height: 50%;', 'height: 100%;']);
    });

    it('draws a chart of nothing without dividing by nothing', () => {
        const wrapper = mount(BarChart, {
            props: {
                label: 'Test',
                series,
                groups: [{ key: '1', xLabel: '01.', ariaLabel: 'eins', stacks: [[{ series: 'a', value: 0 }, { series: 'b', value: 0 }]] }],
            },
        });

        expect(wrapper.html()).not.toContain('NaN');
        expect(wrapper.html()).toContain('height: 0%');
    });

    it('truncates a stack that overshoots a given ceiling instead of rescaling it', () => {
        // The trap this guards. Flex children whose percentage heights add up
        // to more than the track do not get cut off, they shrink —
        // proportionally, all of them — so a stack over the ceiling would
        // quietly restate every value in it and look perfectly fine doing so.
        const heightsFor = (b) => mount(BarChart, {
            props: {
                label: 'Test',
                max: 10,
                series,
                groups: [{
                    key: '1',
                    xLabel: '01.',
                    ariaLabel: 'eins',
                    stacks: [[{ series: 'a', value: 4 }, { series: 'b', value: b }]],
                }],
            },
        }).findAll('[role="listitem"] [style]').map((node) => node.attributes('style'));

        // Rendered top-down, so `b` comes first.
        const within = heightsFor(2);
        const over = heightsFor(400);

        expect(within).toEqual(['height: 20%;', 'height: 40%;']);

        // The stack fills the track exactly — no more, which is what would
        // have made it shrink, and no less.
        expect(over).toEqual(['height: 60%;', 'height: 40%;']);

        // And the segment underneath is untouched: 4 is 40% of the ceiling
        // whatever is stacked on top of it.
        expect(over[1]).toBe(within[1]);
    });

    it('draws a bucket with something in it as a bar rather than as a subpixel', () => {
        const wrapper = mount(BarChart, {
            props: {
                label: 'Test',
                series,
                groups: [
                    { key: '1', xLabel: '01.', ariaLabel: 'eins', stacks: [[{ series: 'a', value: 500 }]] },
                    // Two thousandths of the axis: below a pixel on any track
                    // this component is drawn at, so without a floor the chart
                    // claims an empty bucket where somebody signed up.
                    { key: '2', xLabel: '02.', ariaLabel: 'zwei', stacks: [[{ series: 'a', value: 1 }]] },
                    { key: '3', xLabel: '03.', ariaLabel: 'drei', stacks: [[{ series: 'a', value: 0 }]] },
                ],
            },
        });

        const heights = wrapper.findAll('[role="listitem"] [style]')
            .map((node) => Number.parseFloat(node.attributes('style').replace(/[^\d.]/g, '')));

        expect(heights[0]).toBe(100);
        // Visible, and still unmistakably the smallest bar on the chart.
        expect(heights[1]).toBeGreaterThanOrEqual(1);
        expect(heights[1]).toBeLessThan(3);
        // An empty bucket stays empty — the floor is for something, not for
        // nothing.
        expect(heights[2]).toBe(0);
    });

    it('paints a colour a dark Control Panel can also show', () => {
        const wrapper = mount(BarChart, {
            props: {
                label: 'Test',
                series,
                groups: [{ key: '1', xLabel: '01.', ariaLabel: 'eins', stacks: [[{ series: 'b', value: 1 }]] }],
            },
        });

        // The series carries the colour, and the legend swatch and the bar are
        // therefore the same class by construction — a legend cannot drift
        // from the bars it explains.
        expect(wrapper.html()).toContain('dark:bg-red-500');
    });
});
