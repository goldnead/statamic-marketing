import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@statamic/cms/inertia';
import { captureRouter, press } from './helpers.js';
import Dashboard from '../../resources/js/pages/Dashboard.vue';
// Die drei Zustaende des Doppel-Opt-in werden dort geprueft, wo sie seit dem
// 07.09.2026 bearbeitet werden: auf der Detailseite. `Lists/Edit.vue` legt nur
// noch an und hat deshalb keinen gespeicherten Wert mehr zu lesen.
import ListsShow from '../../resources/js/pages/Lists/Show.vue';

/**
 * The values that are false, zero or empty and still mean something.
 *
 * `??` and `||` differ only for `false`, `0` and `''`, which is exactly the set
 * of values this control panel stores on purpose: a campaign that reached zero
 * recipients, a list whose double opt-in is switched off. Swapping one operator
 * for the other is a one-character edit that changes nothing in any test that
 * uses non-empty data — the reason it is worth pinning the few places where a
 * stored `false` or `0` has to survive the round trip.
 *
 * Nothing here is a bug fix. Every one of these reads correctly in 1.6.0; these
 * tests exist so the next edit to them fails out loud. Each was checked against
 * the broken form: replacing the operator turns the matching test red.
 */

function dashboard(campaign) {
    return mount(Dashboard, {
        props: {
            totalSubscribed: 0,
            totalPending: 0,
            listCount: 1,
            lists: [],
            recentCampaigns: [campaign],
            createCampaignUrl: '/cp/marketing/campaigns/create',
            createListUrl: '/cp/marketing/lists/create',
        },
    });
}

function campaign(overrides = {}) {
    return {
        handle: 'march',
        name: 'March',
        subject: 'Hi',
        status: 'sent',
        sent_at: null,
        url: '/cp/marketing/campaigns/march',
        recipients: 12,
        open_rate: 40,
        ...overrides,
    };
}

describe('a campaign that reached nobody', () => {
    it('reports zero recipients as zero, not as unknown', () => {
        // `campaign.recipients ?? '—'`. With `||` a campaign whose list was
        // empty would be indistinguishable from one that was never sent.
        const wrapper = dashboard(campaign({ recipients: 0 }));

        expect(wrapper.text()).toContain('0');
        expect(wrapper.text()).not.toContain('—');
    });

    it('still reports an unsent campaign as unknown', () => {
        const wrapper = dashboard(campaign({ recipients: null, open_rate: null }));

        expect(wrapper.text()).toContain('—');
    });

    it('reports a zero open rate as zero per cent', () => {
        const wrapper = dashboard(campaign({ open_rate: 0 }));

        expect(wrapper.text()).toContain('0%');
    });
});

describe('a list whose double opt-in is switched off', () => {
    function edit(doubleOptIn) {
        return mount(ListsShow, {
            props: {
                list: {
                    handle: 'newsletter', name: 'Newsletter', description: null,
                    double_opt_in: doubleOptIn, double_opt_in_effective: true,
                },
                stats: { subscribed: 0, pending: 0, unsubscribed: 0, bounced: 0, complained: 0, total: 0 },
                subscribers: [],
                columns: [],
                pagination: { current_page: 1, last_page: 1, total: 0 },
                filters: { status: '', search: '' },
                updateUrl: '/cp/marketing/lists/newsletter',
                deleteUrl: null,
                defaultDoubleOptIn: true,
                addSubscriberUrl: '/cp/marketing/lists/newsletter/subscribers',
                canManageSubscribers: true,
                canManage: true,
            },
        });
    }

    // Nach dem Wert gesucht, nicht nach der Position: auf der Detailseite steht
    // noch eine zweite Auswahl (der Status-Filter), und ein Test, der die erste
    // im Baum nimmt, prueft beim naechsten Umbau stillschweigend die falsche.
    function selected(wrapper) {
        return wrapper.findAllComponents({ name: 'Select' })
            .map((select) => select.attributes('data-attr-model-value'))
            .find((value) => ['default', 'on', 'off'].includes(value));
    }

    it('reads a stored false as an explicit off, not as "use the default"', () => {
        // The three states are distinct and only one of them is falsy: `null`
        // means "follow the config", `false` means "this list overrides it to
        // off". Collapsing them would silently switch double opt-in back on for
        // every list that had deliberately turned it off — the difference
        // between asking for a confirmation and not asking for one.
        expect(selected(edit(false))).toBe('off');
    });

    it('reads a stored true as on and no opinion as the default', () => {
        expect(selected(edit(true))).toBe('on');
        expect(selected(edit(null))).toBe('default');
    });

    // Ueber den Speichern-Knopf statt ueber eine interne Methode: was zaehlt,
    // ist was auf die Reise geht, nicht was eine Hilfsfunktion zurueckgibt.
    it('sends the three states back unchanged', () => {
        for (const [gespeichert, erwartet] of [[false, false], [true, true], [null, null]]) {
            const calls = captureRouter();
            const wrapper = edit(gespeichert);

            press(wrapper, 'Save');

            expect(calls.at(-1).verb).toBe('patch');
            expect(router.patch.mock.calls.at(-1)[1].double_opt_in).toBe(erwartet);
        }
    });
});
