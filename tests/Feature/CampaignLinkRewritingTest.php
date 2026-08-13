<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Support\DeliveryHeaders;
use Goldnead\Marketing\Support\PreferenceLink;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * Which links the renderer sends through the signed redirect, and which it
 * leaves alone.
 *
 * The ones it leaves alone all carry their token in the path and are the
 * routes a reader must be able to reach when everything else has failed:
 * confirm, unsubscribe, and the preference page. The last of those was missing
 * from the list, so preference links were rewritten like any other — and
 * inherited the 403 that a sending platform's appended parameter produces.
 *
 * Since v1.9.0 the preference page is not marketing's. It belongs to
 * `goldnead/statamic-preference-center`, `route('marketing.preferences')` does
 * not exist, and no path in this addon spells it. The renderer therefore may
 * not recognise it by a literal path — it asks {@see PreferenceLink}, the one
 * resolver that knows where a subscriber's links go, and keeps whatever comes
 * back out of the redirect. The centre is optional and is not installed in
 * this suite, so it is simulated the way `PreferenceLinkTest` simulates it:
 * facade class present, token route in the registry.
 */
beforeEach(function (): void {
    // The array transport rather than `Mail::fake()`: a fake stores the
    // mailable and never builds a message, and a header added through
    // `withSymfonyMessage` only exists on the built message. Nothing leaves the
    // process either way.
    config()->set('mail.default', 'array');

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');

    $this->subscription = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com');

    $this->message = Message::create([
        'campaign_handle' => 'welcome',
        'subscription_id' => $this->subscription->id,
        'email' => $this->subscription->email,
        'status' => Message::STATUS_SENT,
        'sent_at' => now(),
    ]);
});

function renderWithLinks(string $content): string
{
    return app(CampaignRenderer::class)->render(
        new Campaign(handle: 'welcome', name: 'Welcome', subject: 'Hello', content: $content),
        test()->list,
        test()->subscription,
        test()->message,
    )->html;
}

it('leaves marketing\'s own self-service links out of the click redirect', function (): void {
    $token = $this->subscription->token;

    $html = renderWithLinks(implode(' ', [
        '<a href="'.route('marketing.unsubscribe', ['token' => $token]).'">Unsubscribe</a>',
        '<a href="'.route('marketing.confirm', ['token' => $token]).'">Confirm</a>',
    ]));

    expect($html)
        ->toContain(route('marketing.unsubscribe', ['token' => $token]))
        ->and($html)->toContain(route('marketing.confirm', ['token' => $token]));

    // Nothing in this message went through the tracking redirect at all.
    expect($html)->not->toContain('/c/'.$this->message->uuid);
});

it('leaves the preference centre out of the click redirect once it is installed', function (): void {
    // The defect. There is no `marketing.preferences` route any more, so the
    // renderer cannot recognise the page by a path it owns — with the centre
    // installed, its link went through the signed redirect like any other and
    // took the provider-parameter 403 with it. The reader who wanted to change
    // what they receive got a 403 instead of the page.
    marketingFakePreferenceCenterClass();
    marketingFakePreferenceCenterRoute();

    $centre = app(PreferenceLink::class)->manage($this->subscription->token);

    // Guard the guard: if this were still marketing's unsubscribe URL, the
    // assertion below would pass on the literal `/unsubscribe/` check and
    // prove nothing about the centre.
    expect($centre)->toContain('/!/preference-center/t/');

    $html = renderWithLinks('<a href="'.$centre.'">Preferences</a>');

    expect($html)->toContain($centre)
        ->and($html)->not->toContain('/c/'.$this->message->uuid);
});

it('leaves the preference link alone when the footer has tagged it', function (): void {
    // A template that writes `{{ unsubscribe_url }}?utm_source=newsletter` is
    // still pointing at the same page. Matching the one exact URL the resolver
    // returned would rewrite this one and hand it the same 403.
    marketingFakePreferenceCenterClass();
    marketingFakePreferenceCenterRoute();

    $tagged = app(PreferenceLink::class)->manage($this->subscription->token).'?utm_source=newsletter';

    $html = renderWithLinks('<a href="'.$tagged.'">Preferences</a>');

    expect($html)->toContain($tagged)
        ->and($html)->not->toContain('/c/'.$this->message->uuid);
});

it('still sends an ordinary link through the click redirect', function (): void {
    $html = renderWithLinks('<a href="https://example.com/news">News</a>');

    expect($html)->toContain('/c/'.$this->message->uuid)
        ->and($html)->toContain('signature=');
});

it('still tracks an ordinary link while a preference centre is installed', function (): void {
    // The self-service exemption is an exemption, not an off switch. If the
    // prefix the resolver hands over were empty or a bare host, every link in
    // the message would silently stop being counted.
    marketingFakePreferenceCenterClass();
    marketingFakePreferenceCenterRoute();

    $html = renderWithLinks('<a href="https://example.com/news">News</a>');

    expect($html)->toContain('/c/'.$this->message->uuid)
        ->and($html)->toContain('signature=');
});

/* --------------------------------------------------- the header, half two */

it('adds no delivery headers until a host names some', function (): void {
    // An addon that guessed the provider and changed how it behaves would be
    // worse than one that asks.
    expect(config('marketing.delivery.mail_headers'))->toBe([]);
});

/**
 * Actually put it on the wire. A header added through `withSymfonyMessage` only
 * exists once the mailable has been sent, so the array transport is the only
 * place the assertion can honestly be made; `Mail::fake()` never builds a
 * message at all.
 */
function headersOfSent(Mailable $mailable): Email
{
    $captured = null;

    Event::listen(MessageSent::class, function ($event) use (&$captured) {
        $captured = $event->message;
    });

    Mail::to('jane@example.com')->send($mailable);

    expect($captured)->not->toBeNull();

    return $captured;
}

it('carries the configured delivery headers on a campaign message', function (): void {
    config()->set('marketing.delivery.mail_headers', [
        'X-Mailgun-Track-Clicks' => 'no',
        'X-PM-TrackLinks' => 'None',
    ]);

    $campaign = new Campaign(handle: 'welcome', name: 'Welcome', subject: 'Hello', content: 'Hi');

    $rendered = app(CampaignRenderer::class)->render($campaign, $this->list, $this->subscription);

    $email = headersOfSent(new CampaignMail($campaign, $rendered));

    expect($email->getHeaders()->get('X-Mailgun-Track-Clicks')?->getBodyAsString())->toBe('no')
        ->and($email->getHeaders()->get('X-PM-TrackLinks')?->getBodyAsString())->toBe('None')
        // The header the addon already set is still there and was not displaced.
        ->and($email->getHeaders()->has('List-Unsubscribe'))->toBeTrue();
});

it('carries the configured delivery headers on a double-opt-in message', function (): void {
    config()->set('marketing.delivery.mail_headers', [
        'X-Mailjet-TrackClick' => '0',
    ]);

    $email = headersOfSent(new ConfirmSubscriptionMail($this->list, $this->subscription));

    expect($email->getHeaders()->get('X-Mailjet-TrackClick')?->getBodyAsString())->toBe('0');
});

it('ignores header entries that could not become a header', function (): void {
    config()->set('marketing.delivery.mail_headers', [
        '' => 'no',
        'X-Good' => 'yes',
        'X-Array' => ['no'],
        'X-Numeric' => 0,
    ]);

    expect(DeliveryHeaders::configured())
        ->toBe(['X-Good' => 'yes', 'X-Numeric' => '0']);
});

/**
 * Ein `href` ist nicht dasselbe wie ein Link.
 *
 * Bis 2.3.0 traf die Umschreibung jedes `href` im Dokument, also auch das
 * `<link rel="stylesheet">` im Kopf einer echten Vorlage. Am 13.08.2026 an
 * Adrian Goldners Newsletter-Layout gemessen: die Schriftart lief über den
 * Klickzähler. Jeder Mailclient, der die Schrift lädt, zählte damit einen
 * Klick auf eine Kampagne, die niemand angeklickt hatte — und die Typografie
 * der Mail hing daran, dass die signierte Weiterleitung funktioniert.
 */
it('rewrites anchors only, never a stylesheet or an image', function (): void {
    $html = renderWithLinks(implode(' ', [
        '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Test">',
        '<img src="https://example.com/bild.png">',
        '<a href="https://example.com/news">News</a>',
    ]));

    expect($html)->toContain('href="https://fonts.googleapis.com/css2?family=Test"')
        ->and($html)->toContain('src="https://example.com/bild.png"');

    // Und der Anker selbst geht weiterhin über den Zähler.
    expect($html)->toContain('/c/'.$this->message->uuid);
});

it('keeps the other attributes of the anchor it rewrites', function (): void {
    $html = renderWithLinks('<a class="cta" href="https://example.com/news" style="color:#2f6b4f;">News</a>');

    expect($html)->toContain('class="cta"')
        ->and($html)->toContain('style="color:#2f6b4f;"')
        ->and($html)->toContain('/c/'.$this->message->uuid);
});
