<?php

use Goldnead\Leadhub\Models\Event;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

/**
 * subscribeWithConsent(): the subscription carries the wording the person was
 * shown, how they gave it and when, in meta['consent'].
 */
beforeEach(function (): void {
    Mail::fake();

    $this->travelTo('2026-10-20 09:00:00');

    $this->wording = 'Ich möchte den Newsletter erhalten und stimme der Verarbeitung meiner Daten zu.';

    $this->consent = fn (array $overrides = []): array => array_merge([
        'method' => 'booking_checkbox',
        'text' => $this->wording,
        'source' => 'cal.com:kostenlose-stimmanalyse',
        'given_at' => '2026-10-20T08:55:00+02:00',
    ], $overrides);

    $this->list = function (bool $doubleOptIn): MailingList {
        $list = new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: $doubleOptIn);
        app(MailingListRepository::class)->save($list);

        return $list;
    };

    $this->service = fn (): SubscriptionService => app(SubscriptionService::class);
});

it('ignores skip_confirmation in the options, the list decides', function (): void {
    $list = ($this->list)(true);

    $subscription = ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)(), [], [
        'skip_confirmation' => true,
    ]);

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_PENDING);
});

it('subscribes at once without double opt-in and records the consent in full', function (): void {
    $list = ($this->list)(false);

    $subscription = ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)(), [], [
        'meta' => ['campaign' => 'stimmanalyse'],
    ]);

    $subscription->refresh();

    expect($subscription->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and($subscription->source)->toBe('cal.com:kostenlose-stimmanalyse')
        ->and($subscription->meta['campaign'])->toBe('stimmanalyse')
        // toEqual, not toBe: MySQL stores JSON with its own key order.
        ->and($subscription->meta['consent'])->toEqual([
            'method' => 'booking_checkbox',
            'list' => 'newsletter',
            'text' => $this->wording,
            'text_sha256' => hash('sha256', $this->wording),
            'source' => 'cal.com:kostenlose-stimmanalyse',
            'requested_at' => '2026-10-20T08:55:00+02:00',
            'confirmed_at' => '2026-10-20T08:55:00+02:00',
        ]);

    Mail::assertNotSent(ConfirmSubscriptionMail::class);
});

it('defaults the consent time to now and the source option to the consent source', function (): void {
    $list = ($this->list)(false);

    $consent = ($this->consent)();
    unset($consent['given_at']);

    $subscription = ($this->service)()->subscribeWithConsent($list, 'maria@example.com', $consent);

    expect($subscription->meta['consent']['requested_at'])->toBe('2026-10-20T09:00:00+00:00')
        ->and($subscription->meta['consent']['confirmed_at'])->toBe('2026-10-20T09:00:00+00:00')
        ->and($subscription->source)->toBe('cal.com:kostenlose-stimmanalyse');
});

it('waits for the confirmation with double opt-in and stamps confirmed_at when the link is used', function (): void {
    $list = ($this->list)(true);

    $subscription = ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)());

    expect($subscription->status)->toBe(Subscription::STATUS_PENDING)
        ->and($subscription->meta['consent']['requested_at'])->toBe('2026-10-20T08:55:00+02:00')
        ->and($subscription->meta['consent']['confirmed_at'])->toBeNull();

    Mail::assertSent(ConfirmSubscriptionMail::class);

    $this->travelTo('2026-10-20 10:30:00');
    $confirmed = ($this->service)()->confirmByToken($subscription->confirmation_token);

    expect($confirmed->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and($confirmed->meta['consent']['text_sha256'])->toBe(hash('sha256', $this->wording))
        ->and($confirmed->meta['consent']['requested_at'])->toBe('2026-10-20T08:55:00+02:00')
        ->and($confirmed->meta['consent']['confirmed_at'])->toBe('2026-10-20T10:30:00+00:00');
});

it('leaves a confirmation without a recorded consent as it was', function (): void {
    $list = ($this->list)(true);

    $subscription = ($this->service)()->subscribe($list, 'maria@example.com');

    ($this->service)()->confirmByToken($subscription->confirmation_token);

    expect($subscription->fresh()->meta)->not->toHaveKey('consent');
});

it('refuses an empty text, and names the field', function (): void {
    $list = ($this->list)(false);

    ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)(['text' => '   ']));
})->throws(InvalidArgumentException::class, 'text');

it('refuses a missing method and writes nothing', function (): void {
    $list = ($this->list)(false);

    try {
        ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)(['method' => '']));
        $this->fail('Expected an InvalidArgumentException.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('method');
    }

    expect(Subscription::query()->count())->toBe(0);
});

it('refuses a missing source', function (): void {
    $list = ($this->list)(false);

    ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)(['source' => '']));
})->throws(InvalidArgumentException::class, 'source');

it('leaves an address that is already subscribed exactly as it was', function (): void {
    $list = ($this->list)(false);
    $first = ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)());

    $snapshot = fn (Subscription $s): array => [
        'meta' => $s->fresh()->meta,
        'source' => $s->fresh()->source,
        'confirmed_at' => $s->fresh()->confirmed_at?->toIso8601String(),
        'updated_at' => $s->fresh()->updated_at?->toIso8601String(),
    ];
    $before = $snapshot($first);

    $this->travelTo('2026-10-21 09:00:00');

    $again = ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)([
        'text' => 'Ein anderer Wortlaut.',
        'given_at' => '2026-10-21T08:00:00+02:00',
    ]), [], ['source' => 'anders']);

    $after = $snapshot($again);

    expect($after)->toBe($before)
        ->and($after['meta']['consent']['text'])->toBe($this->wording)
        ->and(Subscription::query()->count())->toBe(1);
});

it('brings back an unsubscribed address with the new consent in place of the old one', function (): void {
    $list = ($this->list)(false);
    $subscription = ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)());
    $subscription->update(['status' => Subscription::STATUS_UNSUBSCRIBED, 'unsubscribed_at' => now()]);

    $this->travelTo('2026-11-02 09:00:00');

    $back = ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)([
        'text' => 'Ich möchte wieder dabei sein.',
        'given_at' => '2026-11-02T08:58:00+01:00',
    ]));

    expect($back->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and($back->fresh()->meta['consent']['text'])->toBe('Ich möchte wieder dabei sein.')
        ->and($back->fresh()->meta['consent']['requested_at'])->toBe('2026-11-02T08:58:00+01:00');
});

it('refuses a given_at that is not a date', function (): void {
    $list = ($this->list)(false);

    ($this->service)()->subscribeWithConsent($list, 'maria@example.com', ($this->consent)(['given_at' => 'gestern irgendwann']));
})->throws(InvalidArgumentException::class, 'given_at');
