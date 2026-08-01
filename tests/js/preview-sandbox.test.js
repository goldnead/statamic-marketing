import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CampaignsEdit from '../../resources/js/pages/Campaigns/Edit.vue';

/**
 * The frame-side half of the preview barrier.
 *
 * The preview iframe shows HTML that a Control Panel user wrote. It is served
 * from a Control Panel route, so without a `sandbox` attribute the document
 * inside the frame is same-origin with the Control Panel and a `<script>` in a
 * campaign template runs with the previewing user's session. `sandbox=""` puts
 * the frame in a unique opaque origin with scripts off; every token added back
 * is a hole, and two of them reopen this one:
 *
 *   - `allow-scripts` turns execution back on.
 *   - `allow-same-origin` returns the frame to the Control Panel's origin, and
 *     together with `allow-scripts` it is documented as equivalent to no
 *     sandbox at all — the frame can then simply remove its own attribute.
 *
 * So the assertion is not "there is a sandbox attribute". It is "the sandbox
 * attribute grants neither of those two", which is the property that actually
 * holds the boundary. A future change that adds `allow-popups` for a legitimate
 * reason should pass; one that adds `allow-same-origin` should not.
 *
 * The response-side half — the Content-Security-Policy on the preview route —
 * is in `tests/Feature/CampaignPreviewIsolationTest.php`.
 */

function campaignsEdit() {
    return mount(CampaignsEdit, {
        props: {
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
            showUrl: '/x',
            lists: [{ value: 'newsletter', label: 'Newsletter' }],
            segments: [],
            templates: [{ value: 'branded', label: 'Branded' }],
            editable: true,
            canSend: true,
        },
    });
}

async function previewFrame() {
    const wrapper = campaignsEdit();

    // The frame is behind the "Show preview" toggle, so it has to be opened
    // before there is anything to inspect — the same door the user goes
    // through.
    wrapper.vm.showPreview = true;
    await wrapper.vm.$nextTick();

    const frame = wrapper.find('iframe');

    expect(frame.exists()).toBe(true);

    return frame;
}

describe('campaign preview iframe', () => {
    it('carries a sandbox attribute', async () => {
        const frame = await previewFrame();

        expect(frame.attributes('sandbox')).toBeDefined();
    });

    it('grants the frame neither scripts nor the Control Panel origin', async () => {
        const frame = await previewFrame();

        const tokens = (frame.attributes('sandbox') ?? '').split(/\s+/).filter(Boolean);

        expect(tokens).not.toContain('allow-scripts');
        expect(tokens).not.toContain('allow-same-origin');
    });

    it('names the frame, so a screen reader can say what it is', async () => {
        const frame = await previewFrame();

        expect(frame.attributes('title')).toBeTruthy();
    });
});
