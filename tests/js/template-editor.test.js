import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@statamic/cms/inertia';
import TemplatesEdit from '../../resources/js/pages/Templates/Edit.vue';

/**
 * The layout editor's preview loop.
 *
 * The screen this replaced was a textarea whose only feedback was save → write a
 * campaign → send yourself a test. What the loop has to get right is not the
 * rendering — that happens on the server, through the same parser as a real
 * send — but the two ways a live preview goes wrong: it paints a stale answer
 * over a newer one, or it blanks itself the moment somebody opens a brace.
 */

function editor(props = {}) {
    return mount(TemplatesEdit, {
        props: {
            template: { handle: 'branded', name: 'Branded', html: '<div>{{ content }}</div>' },
            storeUrl: null,
            updateUrl: '/cp/marketing/templates/branded',
            deleteUrl: null,
            starterHtml: '',
            previewUrl: '/cp/marketing/templates/preview',
            ...props,
        },
    });
}

/** One queued reply from the preview endpoint. */
function reply(data) {
    return {
        ok: true,
        json: async () => ({ data: { html: '', error: null, findings: [], ...data } }),
    };
}

beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue(reply({ html: '<p>rendered</p>' }));
    window.Statamic = { ...(window.Statamic ?? {}), $config: { get: () => 'token' } };
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('the layout editor', () => {
    it('asks the server for a preview as soon as it opens', async () => {
        const wrapper = editor();
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledTimes(1);

        const [url, options] = global.fetch.mock.calls[0];

        expect(url).toBe('/cp/marketing/templates/preview');
        expect(JSON.parse(options.body).html).toBe('<div>{{ content }}</div>');
        expect(wrapper.find('iframe').attributes('srcdoc')).toBe('<p>rendered</p>');
    });

    it('sends the Control Panel\'s CSRF token', async () => {
        editor();
        await flushPromises();

        expect(global.fetch.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('token');
    });

    it('keeps the last render on screen when the layout stops parsing', async () => {
        // Half-typed Antlers is the normal state of a layout being edited. A
        // preview that goes white on every open brace is worse than none.
        const wrapper = editor();
        await flushPromises();

        global.fetch.mockResolvedValue(reply({ html: '', error: 'Unclosed tag' }));

        wrapper.vm.html = '<div>{{ if';
        await wrapper.vm.refreshPreview();
        await flushPromises();

        expect(wrapper.find('iframe').attributes('srcdoc')).toBe('<p>rendered</p>');
        expect(wrapper.find('[data-marketing-template-preview-error]').text()).toContain('Unclosed tag');
    });

    it('ignores a slow answer to a keystroke that is no longer on screen', async () => {
        // Typing puts several requests in flight at once. Without the guard the
        // slow answer to an older keystroke lands last and paints a preview of
        // text nobody can see any more.
        const wrapper = editor();
        await flushPromises();

        let releaseSlow;
        const slow = new Promise((resolve) => { releaseSlow = () => resolve(reply({ html: '<p>old</p>' })); });

        global.fetch.mockReturnValueOnce(slow);
        const first = wrapper.vm.refreshPreview();

        global.fetch.mockResolvedValueOnce(reply({ html: '<p>new</p>' }));
        await wrapper.vm.refreshPreview();
        await flushPromises();

        releaseSlow();
        await first;
        await flushPromises();

        expect(wrapper.find('iframe').attributes('srcdoc')).toBe('<p>new</p>');
    });

    it('says so when the preview cannot be reached, without losing what is shown', async () => {
        const wrapper = editor();
        await flushPromises();

        global.fetch.mockRejectedValue(new Error('offline'));

        await wrapper.vm.refreshPreview();
        await flushPromises();

        expect(wrapper.vm.previewStale).toBe(true);
        expect(wrapper.find('iframe').attributes('srcdoc')).toBe('<p>rendered</p>');
    });

    it('shows a layout that would send an empty mail as an error, not a warning', async () => {
        const wrapper = editor();

        global.fetch.mockResolvedValue(reply({
            html: '<p>rendered</p>',
            findings: [
                { level: 'error', message: 'never prints the content' },
                { level: 'warning', message: 'no unsubscribe link' },
            ],
        }));

        await wrapper.vm.refreshPreview();
        await flushPromises();

        expect(wrapper.find('[data-marketing-template-findings]').text()).toContain('never prints the content');
        expect(wrapper.find('[data-marketing-template-warnings]').text()).toContain('no unsubscribe link');
    });

    it('shows no findings boxes when the layout is fine', async () => {
        const wrapper = editor();
        await flushPromises();

        expect(wrapper.find('[data-marketing-template-findings]').exists()).toBe(false);
        expect(wrapper.find('[data-marketing-template-warnings]').exists()).toBe(false);
    });

    it('narrows the frame to a phone', async () => {
        // Most of these mails are read on a phone, and a layout whose first
        // real test at that width is a subscriber's thumb is a layout nobody
        // checked.
        const wrapper = editor();
        await flushPromises();

        const frame = () => wrapper.find('iframe').element.parentElement.className;

        expect(frame()).toContain('max-w-full');

        wrapper.vm.previewWidth = 'mobile';
        await wrapper.vm.$nextTick();

        expect(frame()).toContain('max-w-[390px]');
    });
});

/**
 * The building set, and the one thing about it that has to be said out loud.
 *
 * Blocks are a second input, not a second output: what leaves this screen is
 * the same HTML string a hand-written layout produces, translated on the
 * server. So the editor's job here is narrow — offer the choice once, never
 * again, and send blocks as blocks so the one translator stays the only one.
 */
describe('the layout editor, building blocks', () => {
    const blocksField = {
        blueprint: { tabs: [{ sections: [{ fields: [{ handle: 'blocks', type: 'replicator' }] }] }] },
        values: { blocks: [{ _id: 'a1', type: 'content', enabled: true }] },
        meta: { blocks: { existing: {} } },
    };

    it('offers the choice while creating, and says it is final', async () => {
        const wrapper = editor({
            template: null,
            updateUrl: null,
            storeUrl: '/cp/marketing/templates',
            canChooseType: true,
            blocksField,
        });
        await flushPromises();

        expect(wrapper.find('[data-marketing-template-type]').exists()).toBe(true);
        // Not hidden in a tooltip: somebody who picks blocks and wants HTML an
        // afternoon later has to start over, and that is ours to say first.
        expect(wrapper.find('[data-marketing-template-type-fixed]').exists()).toBe(true);
    });

    it('does not offer the choice once the layout exists', async () => {
        const wrapper = editor({ canChooseType: false });
        await flushPromises();

        expect(wrapper.find('[data-marketing-template-type]').exists()).toBe(false);
    });

    it('shows the blocks instead of the code editor', async () => {
        const wrapper = editor({
            template: { handle: 'gebaut', name: 'Gebaut', type: 'blocks', html: '<p>abgeleitet</p>', blocks: [] },
            canChooseType: false,
            blocksField,
        });
        await flushPromises();

        expect(wrapper.find('[data-marketing-template-blocks]').exists()).toBe(true);
        expect(wrapper.find('[data-marketing-template-code]').exists()).toBe(false);
        // The derived-value warning travels with the screen that can trip over it.
        expect(wrapper.find('[data-marketing-template-derived-hint]').exists()).toBe(true);
    });

    it('sends blocks as blocks, never as HTML built in the browser', async () => {
        // A second translator on this side would be a second truth about what
        // the column holds, and the first divergence between them would show
        // up in somebody's inbox.
        const wrapper = editor({
            template: { handle: 'gebaut', name: 'Gebaut', type: 'blocks', html: '<p>abgeleitet</p>', blocks: [] },
            canChooseType: false,
            blocksField,
        });
        await flushPromises();

        const body = JSON.parse(global.fetch.mock.calls[0][1].body);

        expect(body.type).toBe('blocks');
        expect(body.blocks).toEqual([{ _id: 'a1', type: 'content', enabled: true }]);
        expect(body.html).toBeUndefined();
    });

    it('carries a row added after load into the preview and the save', async () => {
        // The stub container never emits `update:model-value`, so the initial
        // payload alone proves nothing about the round trip: it only echoes
        // the props back. What has to hold is that whatever the container
        // hands over later is what gets previewed and what gets saved — the
        // replicator adds rows to that object long after the page loaded.
        const wrapper = editor({
            template: { handle: 'gebaut', name: 'Gebaut', type: 'blocks', html: '<p>abgeleitet</p>', blocks: [] },
            canChooseType: false,
            blocksField,
        });
        await flushPromises();

        const angereichert = [
            { _id: 'a1', type: 'content', enabled: true },
            { _id: 'z9', type: 'button', enabled: true, label: 'Los', url: 'https://example.com' },
        ];

        await wrapper.findComponent({ name: 'PublishContainer' })
            .vm.$emit('update:model-value', { blocks: angereichert });
        await flushPromises();

        await wrapper.vm.refreshPreview();
        await flushPromises();

        const letzte = JSON.parse(global.fetch.mock.calls.at(-1)[1].body);
        expect(letzte.blocks).toEqual(angereichert);

        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});

        wrapper.vm.save();

        expect(patch).toHaveBeenCalledWith(
            '/cp/marketing/templates/branded',
            expect.objectContaining({ blocks: angereichert }),
            expect.anything(),
        );
        // And no HTML travels alongside: the column is written by the
        // translator on the server, not by anything on this side.
        expect(patch.mock.calls[0][1].html).toBeUndefined();
    });

    it('hands the container the rejection the server put on the blocks field', async () => {
        const wrapper = editor({
            template: { handle: 'gebaut', name: 'Gebaut', type: 'blocks', html: '<p>x</p>', blocks: [] },
            canChooseType: false,
            blocksField,
        });
        await flushPromises();

        wrapper.vm.formErrors = { blocks: 'Es fehlt der Baustein Inhalt der Kampagne.' };
        await wrapper.vm.$nextTick();

        // Once at the field, where a reader actually looks…
        expect(wrapper.find('[data-marketing-template-blocks-error]').text())
            .toContain('Es fehlt der Baustein');

        // …and once in the shape the publish container understands, so a rule
        // on a single block field has somewhere to sit when one arrives.
        expect(wrapper.vm.blockErrors).toEqual({
            blocks: ['Es fehlt der Baustein Inhalt der Kampagne.'],
        });
    });

    it('keeps the device and theme switches for a block layout', async () => {
        // They belong to the preview, not to the way the layout was written.
        const wrapper = editor({
            template: { handle: 'gebaut', name: 'Gebaut', type: 'blocks', html: '<p>x</p>', blocks: [] },
            canChooseType: false,
            blocksField,
        });
        await flushPromises();

        expect(wrapper.find('[data-marketing-template-preview-device]').exists()).toBe(true);
        expect(wrapper.find('[data-marketing-template-preview-scheme]').exists()).toBe(true);
    });
});
