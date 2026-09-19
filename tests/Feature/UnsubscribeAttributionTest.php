<?php

/**
 * An unsubscribe reaches the report of the campaign that caused it.
 *
 * Found on staging, 18.09.2026: five recipients, three of them unsubscribed —
 * one through the footer link, one through the RFC 8058 one-click POST, one
 * through the preference center — and the campaign report said "Unsubscribed
 * 0". The list page counted three. The report counts `MessageEvent` rows of
 * type `unsubscribe`, and {@see SubscriptionService::unsubscribe()} only wrote
 * one when the caller named a `message_id`. None of the three paths knows the
 * message: the link carries the subscription token and nothing else. So the
 * figure was zero on every installation since the report existed.
 *
 * The fix looks the message up rather than changing the links: the last mail
 * that actually left for this subscription is the one the person was reading
 * when they decided. That covers the preference center too, which has no link
 * to carry anything in.
 */

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Services\EspEventProcessor;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'brief',
        name: 'Der Brief',
        subject: 'Hallo',
        listHandle: 'newsletter',
    ));

    $this->subscription = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'jane@example.com',
    );

    /** A delivery to the subscription above, as the send path leaves it. */
    $this->delivered = fn (string $campaign, array $attributes = []): Message => Message::create([
        'campaign_handle' => $campaign,
        'subscription_id' => $this->subscription->id,
        'email' => 'jane@example.com',
        'status' => Message::STATUS_SENT,
        'sent_at' => now()->subHour(),
        ...$attributes,
    ]);

    $this->unsubscribeEvents = fn () => MessageEvent::query()
        ->where('type', MessageEvent::TYPE_UNSUBSCRIBE)
        ->get();

    $this->reportedUnsubscribes = fn (string $handle = 'brief'): int => app(CampaignStats::class)
        ->forCampaign(app(CampaignRepository::class)->find($handle))['unsubscribed'];
});

it('counts an unsubscribe through the mail link on the campaign that was sent', function (): void {
    $message = ($this->delivered)('brief');

    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])->assertOk();

    $events = ($this->unsubscribeEvents)();

    expect($events)->toHaveCount(1)
        ->and($events->first()->message_id)->toBe($message->id)
        ->and(($this->reportedUnsubscribes)())->toBe(1);
});

it('counts a one-click unsubscribe the same way', function (): void {
    $message = ($this->delivered)('brief');

    $this->post(route('marketing.unsubscribe.post', $this->subscription->token))->assertNoContent();

    expect(($this->unsubscribeEvents)()->first()?->message_id)->toBe($message->id)
        ->and(($this->reportedUnsubscribes)())->toBe(1);
});

it('counts an unsubscribe from the preference center, which has no link to carry a message in', function (): void {
    $message = ($this->delivered)('brief');

    app(SubscriptionService::class)->unsubscribe($this->subscription, ['reason' => 'preference_center']);

    expect(($this->unsubscribeEvents)()->first()?->message_id)->toBe($message->id)
        ->and(($this->reportedUnsubscribes)())->toBe(1);
});

it('attributes the unsubscribe to the most recent delivery, not the first', function (): void {
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'zweiter',
        name: 'Der zweite Brief',
        subject: 'Nochmal',
        listHandle: 'newsletter',
    ));

    ($this->delivered)('brief', ['sent_at' => now()->subDays(7)]);
    $latest = ($this->delivered)('zweiter', ['sent_at' => now()->subHour()]);

    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])->assertOk();

    expect(($this->unsubscribeEvents)()->first()?->message_id)->toBe($latest->id)
        ->and(($this->reportedUnsubscribes)('zweiter'))->toBe(1)
        ->and(($this->reportedUnsubscribes)('brief'))->toBe(0);
});

it('ignores a message that never left', function (): void {
    // A failed or pending row is not something the person can have read. If
    // it were the newest row it must not steal the unsubscribe from the mail
    // that did arrive.
    $sent = ($this->delivered)('brief', ['sent_at' => now()->subDay()]);
    ($this->delivered)('brief', ['status' => Message::STATUS_FAILED, 'sent_at' => null, 'error' => 'Mailbox full']);

    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])->assertOk();

    expect(($this->unsubscribeEvents)()->first()?->message_id)->toBe($sent->id);
});

it('writes no event when nothing was ever sent to the subscription', function (): void {
    // Somebody who signed up and left before the first campaign has not
    // unsubscribed "through" anything. An event with no message would be a
    // row the report could not place, and the unsubscribe itself must still
    // go through.
    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(($this->unsubscribeEvents)())->toHaveCount(0);
});

it('keeps a message the caller named ahead of the inferred one', function (): void {
    $older = ($this->delivered)('brief', ['sent_at' => now()->subDays(3)]);
    ($this->delivered)('brief', ['sent_at' => now()->subHour()]);

    app(SubscriptionService::class)->unsubscribe($this->subscription, [
        'reason' => 'esp_unsubscribe',
        'message_id' => $older->id,
    ]);

    expect(($this->unsubscribeEvents)()->first()?->message_id)->toBe($older->id);
});

it('counts a provider-reported unsubscribe on the message the provider names', function (): void {
    // The ESP webhook is the one caller that knows the message. It must say
    // so, or the report would count its unsubscribe on whichever mail left
    // last — wrong as soon as two campaigns go out close together.
    $named = ($this->delivered)('brief', ['sent_at' => now()->subDays(2)]);
    ($this->delivered)('brief', ['sent_at' => now()->subHour()]);

    app(EspEventProcessor::class)->process([
        'type' => 'unsubscribe',
        'email' => 'jane@example.com',
        'message_uuid' => $named->uuid,
    ]);

    $event = ($this->unsubscribeEvents)()->first();

    expect($event?->message_id)->toBe($named->id)
        ->and($event?->meta['attribution'] ?? null)->toBe('named');
});

it('records how the message was found, so a direct and an inferred event can be told apart', function (): void {
    ($this->delivered)('brief');

    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])->assertOk();

    $meta = ($this->unsubscribeEvents)()->first()?->meta;

    expect($meta['reason'] ?? null)->toBe('link')
        ->and($meta['attribution'] ?? null)->toBe('last_sent');
});
