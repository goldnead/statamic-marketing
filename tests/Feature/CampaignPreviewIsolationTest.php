<?php

/**
 * The campaign preview renders HTML that a Control Panel user wrote — the
 * campaign body and the e-mail template around it — and it renders it on a
 * Control Panel route, which means the same origin as the session that is
 * looking at it. Without a barrier a holder of `manage marketing templates`
 * can put a `<script>` in a template and have it run as whichever super user
 * previews a campaign that uses it. That is not a cross-site problem; the
 * script is already inside the site.
 *
 * Two things have to hold, and they are independent:
 *
 * 1. The *response* forbids execution. A Content-Security-Policy of
 *    `sandbox; default-src 'none'` puts the document in a unique opaque
 *    origin and lets it load nothing — it holds even when the HTML is opened
 *    directly in a tab, which the "Open in new tab" link invites.
 * 2. The *iframe* forbids execution. `sandbox` without `allow-scripts` and
 *    without `allow-same-origin` is the frame-side half, and it is what stops
 *    a browser that ignores the header. That half lives in
 *    `tests/js/preview-sandbox.test.js`, because it is an attribute on a Vue
 *    template and not something a PHP response can see.
 *
 * Both halves are asserted, because either alone is a single point of failure
 * on a privilege-escalation path.
 */

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Statamic\Facades\User;

beforeEach(function (): void {
    $user = User::make()->email('preview@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));

    // The template is the escalation vector: a lower-privileged editor writes
    // it, a super user renders it.
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'hostile',
        name: 'Hostile',
        html: '<script>window.top.document.title = "owned"</script>{{ content }}',
    ));

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'welcome',
        name: 'Welcome',
        subject: 'Hi',
        listHandle: 'newsletter',
        templateHandle: 'hostile',
    ));
});

it('sends a script-forbidding Content-Security-Policy with the preview', function (): void {
    $response = $this->get(cp_route('marketing.campaigns.preview', 'welcome'));

    $response->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toContain('sandbox')
        ->and($csp)->toContain("default-src 'none'");
});

it('does not let the preview response be sniffed into another type', function (): void {
    $response = $this->get(cp_route('marketing.campaigns.preview', 'welcome'));

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('still renders the authored HTML, so the barrier is a barrier and not a filter', function (): void {
    // The point of the sandbox is that the HTML may stay exactly as written.
    // If a future change starts stripping it instead, the preview stops being
    // a preview and this test says so.
    $response = $this->get(cp_route('marketing.campaigns.preview', 'welcome'));

    expect($response->getContent())->toContain('<script>');
});
