import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
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
