import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ListsShow from '../../resources/js/pages/Lists/Show.vue';
import ListsEdit from '../../resources/js/pages/Lists/Edit.vue';
import TemplatesEdit from '../../resources/js/pages/Templates/Edit.vue';
import CampaignsEdit from '../../resources/js/pages/Campaigns/Edit.vue';

/**
 * What this test can and cannot prove.
 *
 * v1.5.0 tried to stop the subscriber e-mail column collapsing by giving it a
 * min-width. It did not work: Statamic's `Field` recipe carries its own
 * `min-w-0`, both classes ended up on the same element, and the one later in
 * the stylesheet won. v1.5.1 fixed it with an explicit width instead. That was
 * a cascade problem, and a cascade needs a browser — jsdom applies no author
 * stylesheet, Tailwind is not compiled here, and `getComputedStyle` would
 * report nothing useful. This layer cannot measure that a field is 26 pixels
 * wide, and pretending otherwise would be worse than not testing it.
 *
 * What it can do is hold the rule the fix established, which is the part that
 * regressed: on a row of `Field`s, width is stated explicitly and never left to
 * `flex-1`. `flex-1` is `flex: 1 1 0%` — a zero basis — and `Field`'s own
 * `min-w-0` removes the min-content floor that would otherwise stop the column
 * from reaching zero. The combination is the defect; the combination is
 * checkable without a browser.
 *
 * It caught the survivor: the search field in the subscriber filter row still
 * had `flex-1 sm:max-w-xs` after v1.5.1 fixed the row above it. A max-width is
 * not a floor and does nothing here.
 */

/** The class the template hands each `Field`, keyed by its label. */
function fieldClasses(wrapper) {
    return wrapper.findAllComponents({ name: 'Field' })
        .map((field) => ({
            label: field.attributes('data-attr-label') ?? '(unlabelled)',
            classes: (field.attributes('data-attr-class') ?? '').split(/\s+/).filter(Boolean),
        }));
}

function listsShow() {
    return mount(ListsShow, {
        props: {
            list: { handle: 'newsletter', name: 'Newsletter', description: null, double_opt_in: true, double_opt_in_effective: true },
            stats: { subscribed: 0, pending: 0, unsubscribed: 0, bounced: 0, complained: 0, total: 0 },
            subscribers: [],
            columns: [],
            pagination: { current_page: 1, last_page: 1, total: 0 },
            filters: { status: '', search: '' },
            editUrl: '/cp/marketing/lists/newsletter/edit',
            addSubscriberUrl: '/cp/marketing/lists/newsletter/subscribers',
            canManageSubscribers: true,
            canManage: true,
        },
    });
}

describe('Field widths', () => {
    it('never lets a Field size itself with flex-1', () => {
        const pages = {
            'Lists/Show': listsShow(),
            'Lists/Edit': mount(ListsEdit, {
                props: {
                    list: { handle: 'newsletter', name: 'Newsletter', description: null, double_opt_in: null },
                    storeUrl: null, updateUrl: '/x', deleteUrl: null, defaultDoubleOptIn: true,
                },
            }),
            'Templates/Edit': mount(TemplatesEdit, {
                props: { template: { handle: 'l', name: 'L', html: '' }, storeUrl: null, updateUrl: '/x', deleteUrl: null, starterHtml: '' },
            }),
            'Campaigns/Edit': mount(CampaignsEdit, {
                props: {
                    campaign: {
                        handle: 'm', name: 'M', subject: null, preheader: null, from_name: null,
                        from_email: null, reply_to: null, list: null, segment: '', template: '',
                        content: '', status: 'draft', scheduled_at: null, sent_at: null,
                    },
                    storeUrl: null, updateUrl: '/x', deleteUrl: null, sendUrl: '/x', scheduleUrl: '/x',
                    unscheduleUrl: '/x', testUrl: '/x', previewUrl: null, showUrl: '/x',
                    lists: [], segments: [], templates: [], editable: true, canSend: true,
                },
            }),
        };

        const offenders = [];

        for (const [page, wrapper] of Object.entries(pages)) {
            for (const field of fieldClasses(wrapper)) {
                if (field.classes.includes('flex-1')) {
                    offenders.push(`${page}: ${field.label}`);
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('gives every Field in the add-subscriber row an explicit width', () => {
        // Three fields and a button on one flex row: each field states its own
        // width, so none of them can be squeezed out by its neighbours.
        const wrapper = listsShow();
        const byLabel = Object.fromEntries(fieldClasses(wrapper).map((field) => [field.label, field.classes]));

        for (const label of ['Email', 'marketing::subscribers.first_name', 'marketing::subscribers.last_name']) {
            expect(byLabel[label]?.some((className) => /^(sm:)?w-\S+$/.test(className)), `${label} has no explicit width`).toBe(true);
        }
    });

    it('gives the search field an explicit width rather than a max-width', () => {
        // The row it sits in also holds the status select and the filter
        // button. `sm:max-w-xs` capped it and set no floor, which is the half
        // of the v1.5.0 attempt that survived into 1.6.0.
        const wrapper = listsShow();
        const search = fieldClasses(wrapper).find((field) => field.label === 'Search');

        expect(search, 'the search field is gone').toBeTruthy();
        expect(search.classes.some((className) => /^(sm:)?w-\S+$/.test(className)), 'no explicit width').toBe(true);
        expect(search.classes).not.toContain('flex-1');
    });
});
