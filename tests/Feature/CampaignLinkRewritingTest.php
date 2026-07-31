<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * Which links the renderer sends through the signed redirect, and which it
 * leaves alone.
 *
 * The three it leaves alone all carry their token in the path and are the
 * routes a reader must be able to reach when everything else has failed:
 * confirm, unsubscribe, and the preference centre. The last of those was
 * missing from the list, so preference links were rewritten like any other —
 * and inherited the 403 that a sending platform's appended parameter produces.
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

it('leaves the self-service links out of the click redirect', function (): void {
    $token = $this->subscription->token;

    $html = renderWithLinks(implode(' ', [
        '<a href="'.route('marketing.preferences', ['token' => $token]).'">Preferences</a>',
        '<a href="'.route('marketing.unsubscribe', ['token' => $token]).'">Unsubscribe</a>',
        '<a href="'.route('marketing.confirm', ['token' => $token]).'">Confirm</a>',
    ]));

    expect($html)
        ->toContain(route('marketing.preferences', ['token' => $token]))
        ->and($html)->toContain(route('marketing.unsubscribe', ['token' => $token]))
        ->and($html)->toContain(route('marketing.confirm', ['token' => $token]));

    // Nothing in this message went through the tracking redirect at all.
    expect($html)->not->toContain('/c/'.$this->message->uuid);
});

it('still sends an ordinary link through the click redirect', function (): void {
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
function headersOfSent(Illuminate\Contracts\Mail\Mailable $mailable): Symfony\Component\Mime\Email
{
    $captured = null;

    Event::listen(Illuminate\Mail\Events\MessageSent::class, function ($event) use (&$captured) {
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

    expect(\Goldnead\Marketing\Support\DeliveryHeaders::configured())
        ->toBe(['X-Good' => 'yes', 'X-Numeric' => '0']);
});
