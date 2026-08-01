<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
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

    $this->subscription = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'jane@example.com',
    );

    $this->message = Message::create([
        'campaign_handle' => 'welcome',
        'subscription_id' => $this->subscription->id,
        'email' => 'jane@example.com',
        'status' => Message::STATUS_SENT,
        'sent_at' => now(),
    ]);
});

it('marks the subscription bounced and opts the contact out on a hard bounce', function (): void {
    $result = app(EspEventProcessor::class)->process([
        'type' => 'bounce',
        'email' => 'jane@example.com',
        'message_uuid' => $this->message->uuid,
        'hard' => true,
    ]);

    expect($result['handled'])->toBeTrue();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_BOUNCED)
        ->and($this->message->fresh()->status)->toBe(Message::STATUS_BOUNCED);

    $contact = LeadHub::findByEmail('jane@example.com');
    $model = Contact::query()->where('uuid', $contact['uuid'])->first();

    expect((bool) $model->do_not_contact)->toBeTrue();
});

it('keeps the subscription on a soft bounce', function (): void {
    app(EspEventProcessor::class)->process([
        'type' => 'bounce',
        'email' => 'jane@example.com',
        'message_uuid' => $this->message->uuid,
        'hard' => false,
    ]);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('normalizes mailgun payloads', function (): void {
    app(EspEventProcessor::class)->process([
        'event-data' => [
            'event' => 'failed',
            'severity' => 'permanent',
            'recipient' => 'jane@example.com',
            'reason' => 'suppress-bounce',
        ],
    ], 'mailgun');

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_BOUNCED);
});

it('normalizes postmark complaints and unsubscribes on them', function (): void {
    app(EspEventProcessor::class)->process([
        'RecordType' => 'SpamComplaint',
        'Email' => 'jane@example.com',
    ], 'postmark');

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_COMPLAINED);
});

it('processes esp unsubscribe events', function (): void {
    app(EspEventProcessor::class)->process([
        'type' => 'unsubscribe',
        'email' => 'jane@example.com',
    ]);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

/**
 * Unknown bounce severity must be treated as SOFT.
 *
 * Every normalizer branch used to default to hard, so a bounce payload that
 * carries no severity at all — a transient failure, an unmapped provider field —
 * was read as permanent: subscription set to `bounced` AND the LeadHub contact
 * opted out. That silently and irreversibly removed valid subscribers. Only an
 * explicitly hard-reported bounce may suppress permanently.
 */
function expectStillSubscribed(): void
{
    expect(test()->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);

    $contact = LeadHub::findByEmail('jane@example.com');

    expect((bool) Contact::query()->where('uuid', $contact['uuid'])->first()->do_not_contact)
        ->toBeFalse();
}

it('treats a bounce without a severity flag as soft', function (): void {
    app(EspEventProcessor::class)->process([
        'type' => 'bounce',
        'email' => 'jane@example.com',
        'message_uuid' => $this->message->uuid,
        // no `hard` key at all — severity unknown.
    ]);

    expectStillSubscribed();
});

it('treats a mailgun failure without a severity as soft', function (): void {
    app(EspEventProcessor::class)->process([
        'event-data' => [
            'event' => 'failed',
            'recipient' => 'jane@example.com',
            'reason' => 'mailbox full',
            // no `severity` key.
        ],
    ], 'mailgun');

    expectStillSubscribed();
});

it('treats a postmark bounce without a type as soft', function (): void {
    app(EspEventProcessor::class)->process([
        'RecordType' => 'Bounce',
        'Email' => 'jane@example.com',
        // no `Type` key.
    ], 'postmark');

    expectStillSubscribed();
});

it('ignores events without a recipient', function (): void {
    $result = app(EspEventProcessor::class)->process(['type' => 'bounce']);

    expect($result['handled'])->toBeFalse();
});
