<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Support\TrackingParameters;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * The tracking link as it arrives, not as it was sent.
 *
 * Nothing local could have found this. A mail sink stores what it was handed; a
 * mail service provider rewrites it. Brevo puts every `href` of the HTML part
 * through its own click counter, and when the counter forwards the reader it
 * appends `_se` — the recipient address in base64 — to the target URL. Laravel
 * signs the whole query string, so the URL that arrives is not the URL that was
 * signed and `ValidateSignature` answers 403 before the controller runs. Twice
 * broken: the reader never reaches the destination, and the click is not
 * counted either.
 *
 * These tests do to the URL what the provider does to it, then follow it.
 *
 * The other half of the file is the boundary of that concession, and it is
 * sharper here than for a magic link. `marketing.track.click` carries its
 * destination *in the query* — `?url=https://…`. A `url` on the ignore list
 * would not be a weakened signature, it would be an open redirect on the
 * sender's own domain. So the tests that matter most are the ones that still
 * refuse: an unnamed parameter, and above all an edited `url`.
 */
beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $subscription = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'jane@example.com',
    );

    $this->message = Message::create([
        'campaign_handle' => 'welcome',
        'subscription_id' => $subscription->id,
        'email' => $subscription->email,
        'status' => Message::STATUS_SENT,
        'sent_at' => now(),
    ]);
});

/** The signed click URL exactly as the renderer produces it. */
function clickLink(string $target = 'https://example.com/news'): string
{
    return URL::signedRoute('marketing.track.click', [
        'uuid' => test()->message->uuid,
        'url' => $target,
    ]);
}

/**
 * The URL as Brevo's redirector hands it on.
 *
 * The parameter goes in front of the rest of the query, which is where the
 * staging capture had it. Position is not decoration: Laravel verifies the raw
 * query string in the order it arrived, so a test that only ever appended at
 * the end would be testing a case the provider does not produce.
 */
function asBrevoForwardsIt(string $url, string $email = 'jane@example.com'): string
{
    [$path, $query] = array_pad(explode('?', $url, 2), 2, '');

    return $path.'?_se='.rtrim(strtr(base64_encode($email), '+/', '-_'), '=').'&'.$query;
}

/* ------------------------------------------------------ what must now work */

it('follows a click link that a click counter has added its own parameter to', function (): void {
    $forwarded = asBrevoForwardsIt(clickLink());

    // The measured step. Before the fix this is a 403.
    $this->get($forwarded)->assertRedirect('https://example.com/news');
});

it('still counts the click when a counter has rewritten the link', function (): void {
    // Reaching the destination is only half of it: the middleware used to abort
    // before `recordClick()`, so the campaign report lost the click as well.
    $this->get(asBrevoForwardsIt(clickLink()))->assertRedirect('https://example.com/news');

    $message = $this->message->fresh();

    expect($message->clicks)->toBe(1)
        ->and(MessageEvent::query()->where('type', 'click')->value('url'))
        ->toBe('https://example.com/news');
});

it('follows a link tagged by the other providers on the list', function (): void {
    // Brevo's Google-Analytics tagging and Mailchimp's ids, both appended to
    // links by the sending platform rather than by anybody's browser.
    $tagged = clickLink().'&utm_source=brevo&utm_medium=email&utm_campaign=news'
        .'&mc_cid=abc123&mc_eid=def456';

    $this->get($tagged)->assertRedirect('https://example.com/news');
});

/* -------------------------------------------------- what must still refuse */

it('refuses a parameter nobody named', function (): void {
    // The concession is a list, not a policy. An attacker who could append
    // anything at all would be back to an unsigned query string.
    $this->get(clickLink().'&anything=1')->assertForbidden();
    $this->get(clickLink().'&_sex=1')->assertForbidden();

    expect($this->message->fresh()->clicks)->toBe(0);
});

it('refuses an edited destination even with a tracking parameter alongside it', function (): void {
    // The whole reason `url` is reserved. If it were ignorable, this request
    // would redirect to boese.example over the sender's own domain, with the
    // sender's own reputation behind it, and the signature would say nothing.
    $forwarded = asBrevoForwardsIt(clickLink());

    $tampered = str_replace(
        'url='.urlencode('https://example.com/news'),
        'url='.urlencode('https://boese.example/phish'),
        $forwarded,
    );

    expect($tampered)->not->toBe($forwarded)
        ->and($tampered)->toContain('boese.example');

    $response = $this->get($tampered);

    $response->assertForbidden();
    expect($response->headers->get('Location'))->toBeNull();
    expect($this->message->fresh()->clicks)->toBe(0);
});

it('refuses a destination smuggled in as a second url parameter', function (): void {
    // PHP hands the controller the last `url`; the signature check sees both.
    // The mismatch is what closes this, and it has to stay closed while `_se`
    // is being overlooked next to it.
    $this->get(asBrevoForwardsIt(clickLink()).'&url='.urlencode('https://boese.example/phish'))
        ->assertForbidden();

    expect($this->message->fresh()->clicks)->toBe(0);
});

it('refuses an edited signature even with a tracking parameter alongside it', function (): void {
    $forwarded = asBrevoForwardsIt(clickLink());

    $this->get(preg_replace('/signature=(.)/', 'signature=$1'.'0', $forwarded, 1))
        ->assertForbidden();
});

it('refuses a link whose expiry was moved, which is why expires is not ignorable', function (): void {
    $url = asBrevoForwardsIt(URL::temporarySignedRoute('marketing.track.click', now()->addMinutes(30), [
        'uuid' => $this->message->uuid,
        'url' => 'https://example.com/news',
    ]));

    $this->get($url)->assertRedirect('https://example.com/news');

    $moved = preg_replace_callback(
        '/expires=(\d+)/',
        fn ($m) => 'expires='.((int) $m[1] + 86400),
        $url,
    );

    expect($moved)->not->toBe($url);

    $this->get($moved)->assertForbidden();
});

it('still refuses an unsigned click URL', function (): void {
    $this->get(route('marketing.track.click', [
        'uuid' => $this->message->uuid,
        'url' => 'https://boese.example',
    ]))->assertForbidden();

    expect($this->message->fresh()->clicks)->toBe(0);
});

/* ------------------------------------------------------------- the guard */

it('drops the three parameters that carry the guarantee, however they are configured', function (): void {
    // `url` is the addition this route needs over the magic-link version: its
    // payload is in the query, so ignoring it is not a weaker signature but an
    // open redirect. No edit of a list of harmless-looking names may buy that.
    config()->set('marketing.delivery.ignored_query_parameters', [
        '_se', 'url', 'URL', ' Url ', 'expires', 'EXPIRES', ' signature ', 'utm_source',
    ]);

    expect(TrackingParameters::ignored())->toBe(['_se', 'utm_source']);
});

it('drops names that would not survive being passed to the router', function (): void {
    config()->set('marketing.delivery.ignored_query_parameters', [
        '_se',
        'one,two',      // two names wearing one name's clothes
        '_se2,url',     // and the way that smuggles the reserved name back in
        'with space',
        '',
        ['nested'],
        '_se',          // and the duplicate a hand-edited list collects
    ]);

    expect(TrackingParameters::ignored())->toBe(['_se']);
});

it('refuses an edited destination even when the config tries to ignore url', function (): void {
    // The guard, proven where it matters rather than on its own return value.
    // A host — or anything that can write the config — asks for `url` to be
    // overlooked, which is the one edit that would turn this endpoint into an
    // open redirect. The list is read per request, so this is the live setting,
    // and the request is still refused.
    config()->set('marketing.delivery.ignored_query_parameters', ['_se', 'url', 'expires', 'signature']);

    $tampered = str_replace(
        'url='.urlencode('https://example.com/news'),
        'url='.urlencode('https://boese.example/phish'),
        asBrevoForwardsIt(clickLink()),
    );

    $response = $this->get($tampered);

    $response->assertForbidden();
    expect($response->headers->get('Location'))->toBeNull()
        ->and($this->message->fresh()->clicks)->toBe(0);

    // And the parameter that was legitimately on the list still works.
    $this->get(asBrevoForwardsIt(clickLink()))->assertRedirect('https://example.com/news');
});

it('ships a list on which every entry is a parameter a mail provider adds', function (): void {
    // A regression fence around the list itself. It is short on purpose, and
    // the day somebody widens it is the day this reads differently.
    expect(TrackingParameters::ignored())->toBe([
        '_se',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'mc_cid', 'mc_eid',
        '_hsenc', '_hsmi',
        'mkt_tok',
    ]);
});
