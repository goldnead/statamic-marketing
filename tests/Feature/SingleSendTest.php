<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\MailLogEntry;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\SingleSend;
use Goldnead\Marketing\Sending\SingleSendResult;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Support\Facades\Mail;

/**
 * The one-recipient send path.
 *
 * A sequence sends the same mail the campaign path sends, to one person, at a
 * moment nobody planned. What these cases hold is that removing the fan-out did
 * not also remove the four gates it went through on the way — and that they are
 * asked in the documented order, because the order is the part that is easy to
 * get subtly wrong and impossible to notice afterwards.
 */
beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->jane = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com', ['first_name' => 'Jane']);

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'welcome-1',
        name: 'Welcome 1',
        subject: 'Hello {{ first_name }}',
        listHandle: 'newsletter',
        content: '<p>Hi.</p>',
        status: Campaign::STATUS_DRAFT,
    ));

    $this->campaign = app(CampaignRepository::class)->find('welcome-1');

    $this->send = fn (?Subscription $subscription = null): SingleSendResult => app(SingleSend::class)->send(
        $this->campaign,
        $this->list,
        $subscription ?? $this->jane->fresh(),
    );

    $this->enableCap = function (int $max = 3): void {
        config()->set('marketing.frequency_cap.enabled', true);
        config()->set('marketing.frequency_cap.max', $max);
        config()->set('marketing.frequency_cap.window_hours', 168);
    };

    $this->fillTheWindow = function (int $count): void {
        foreach (range(1, $count) as $ignored) {
            MailLogEntry::query()->create([
                'brand_id' => app('brand-context')->currentId(),
                'email_normalized' => 'jane@example.com',
                'mail_class' => MailClass::Marketing->value,
                'reference' => 'earlier',
                'sent_at' => now(),
            ]);
        }
    };
});

it('sends one campaign to one subscriber and leaves the campaign in draft', function (): void {
    $result = ($this->send)();

    Mail::assertSent(CampaignMail::class, 1);

    expect($result->wasSent())->toBeTrue()
        ->and($result->message)->not->toBeNull()
        ->and($result->message->status)->toBe(Message::STATUS_SENT);

    // The campaign is the CONTENT of the sequence step, not a broadcast that
    // happened. Flipping it to `sent` here would take it out of every later
    // enrollment and, with the archive flag on, publish it to the open web.
    expect(app(CampaignRepository::class)->find('welcome-1')->status)->toBe(Campaign::STATUS_DRAFT);
});

it('writes a real message row, so the mail is visible to reports and feedback', function (): void {
    $result = ($this->send)();

    $message = Message::query()->firstWhere('uuid', $result->message->uuid);

    expect($message)->not->toBeNull()
        ->and($message->campaign_handle)->toBe('welcome-1')
        ->and($message->subscription_id)->toBe($this->jane->id)
        ->and($message->email)->toBe('jane@example.com')
        // Denormalised from the subscription, because a queue worker resuming
        // this run has no brand of its own and the row does.
        ->and((int) $message->brand_id)->toBe((int) $this->jane->brand_id);
});

it('may send the same campaign to the same person twice', function (): void {
    // Deliberate, and the difference from a broadcast. Somebody who is enrolled
    // in a welcome series a second time gets the welcome series a second time;
    // a `firstOrCreate` on (campaign, subscription) — which is right for a
    // campaign, where a retried snapshot job must not double-create — would
    // silently deliver nothing the second time round.
    ($this->send)();
    ($this->send)();

    Mail::assertSent(CampaignMail::class, 2);

    expect(Message::query()->forCampaign('welcome-1')->count())->toBe(2);
});

/*
 * The four gates, in order.
 */

it('does not send to somebody who is not subscribed to the list', function (): void {
    app(SubscriptionService::class)->unsubscribe($this->jane, ['reason' => 'test']);

    $result = ($this->send)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('not_subscribed')
        // No row either: a mail that was never allowed is not a mail that failed.
        ->and(Message::query()->count())->toBe(0);
});

it('does not send to a suppressed address', function (): void {
    $this->mock(SuppressionGate::class)
        ->shouldReceive('isSuppressed')->andReturn(true);

    $result = ($this->send)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('suppressed');
});

it('does not send when the suppression list cannot be read', function (): void {
    // Fail closed. Not knowing is not permission — the same rule
    // StartCampaignJob applies when it aborts a whole campaign.
    $this->mock(SuppressionGate::class)
        ->shouldReceive('isSuppressed')->andThrow(new SuppressionCheckFailed('store unreachable'));

    $result = ($this->send)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('suppression_unavailable');
});

it('does not send to a contact who has opted out of contact', function (): void {
    $contact = app(ContactRepository::class)->findByEmailNormalized('jane@example.com');

    expect($contact)->not->toBeNull('the subscribe flow must have created a LeadHub contact');

    $contact->do_not_contact = true;
    $contact->save();

    $result = ($this->send)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('opted_out');
});

it('does not send when no contact can be resolved at all', function (): void {
    // Also fail closed. Every path that confirms a subscription creates or
    // resolves a contact, so "no contact" means the CRM sync failed — which may
    // not be read as consent.
    $contact = app(ContactRepository::class)->findByEmailNormalized('jane@example.com');
    $contact->delete();

    $this->jane->update(['contact_uuid' => null]);

    $result = ($this->send)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('opted_out');
});

it('defers rather than blocks a recipient who is at the frequency cap', function (): void {
    ($this->enableCap)(max: 3);
    ($this->fillTheWindow)(3);

    $result = ($this->send)();

    Mail::assertNothingSent();

    // The only outcome that says "ask again". A caller that treated this as a
    // failure would turn a cap into a lost mail.
    expect($result->isDeferred())->toBeTrue()
        ->and($result->isBlocked())->toBeFalse()
        ->and($result->reason)->toBe('frequency_cap')
        ->and($result->retryAfterMinutes)->toBe(1440)
        // Nothing was written: a deferred mail has not been attempted yet.
        ->and(Message::query()->count())->toBe(0);
});

it('asks the cap after suppression, never before it', function (): void {
    // The order matters and is otherwise invisible: both gates end the send, so
    // a swapped pair only shows up as the wrong WORD in a log six months later
    // — "held back until tomorrow" for an address that must never be mailed at
    // all.
    ($this->enableCap)(max: 1);
    ($this->fillTheWindow)(5);

    $this->mock(SuppressionGate::class)
        ->shouldReceive('isSuppressed')->andReturn(true);

    $result = ($this->send)();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('suppressed')
        ->and($result->isDeferred())->toBeFalse();
});

it('honours the cap exceptions a campaign declares', function (): void {
    ($this->enableCap)(max: 1);
    ($this->fillTheWindow)(5);

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'reminder',
        name: 'Reminder',
        subject: 'Tomorrow',
        listHandle: 'newsletter',
        content: '<p>See you.</p>',
        mailClass: MailClass::Reminder->value,
    ));

    $result = app(SingleSend::class)->send(
        app(CampaignRepository::class)->find('reminder'),
        $this->list,
        $this->jane->fresh(),
    );

    Mail::assertSent(CampaignMail::class, 1);

    expect($result->wasSent())->toBeTrue();
});

it('records the send against the cap only after it went out', function (): void {
    ($this->enableCap)(max: 3);

    ($this->send)();

    expect(app(FrequencyCap::class)->countInWindow('jane@example.com'))->toBe(1)
        ->and(MailLogEntry::query()->firstWhere('reference', 'welcome-1'))->not->toBeNull();
});

it('never records a mail that failed to send', function (): void {
    ($this->enableCap)(max: 3);

    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('transport down'));

    $result = ($this->send)();

    expect($result->isFailed())->toBeTrue()
        ->and($result->isBlocked())->toBeFalse()
        ->and(MailLogEntry::query()->count())->toBe(0)
        ->and(Message::query()->first()->status)->toBe(Message::STATUS_FAILED);
});

it('is off by default, so the cap never surprises an existing install', function (): void {
    ($this->fillTheWindow)(50);

    expect(($this->send)()->wasSent())->toBeTrue();
});
