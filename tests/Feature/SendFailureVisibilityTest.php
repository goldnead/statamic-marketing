<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Events\CampaignSent;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Jobs\SendMessageJob;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Sending\BrandMailer;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Three ways a send reported something other than what happened.
 *
 * A mail that left was recorded as failed because something AFTER the send
 * threw. A transport hiccup ended a message for good, and the campaign still
 * finished as "sent", so a thousand failures looked like a clean run. And the
 * job never asked LeadHub whether the person had opted out since the audience
 * was snapshotted, although every other send path does.
 *
 * No Mail::fake() here for the same reason as in DoubleSendTest: the array
 * transport is what proves whether a mail actually went out.
 */
beforeEach(function (): void {
    config()->set('mail.default', 'array');

    app(MailingListRepository::class)->save(
        new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false)
    );
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'stille-kampagne',
        name: 'Test',
        subject: 'Hallo',
        listHandle: 'newsletter',
        content: '<p>Hallo.</p>',
        status: Campaign::STATUS_SENDING,
    ));

    $this->recipient = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'empfaenger@example.com',
    );

    $this->message = Message::create([
        'campaign_handle' => 'stille-kampagne',
        'subscription_id' => $this->recipient->id,
        'email' => 'empfaenger@example.com',
        'status' => Message::STATUS_PENDING,
    ]);
});

function stillRausgegangen(): int
{
    return count(Mail::mailer()->getSymfonyTransport()->messages());
}

function stillSenden(int $messageId, int $attempt = 1): void
{
    app()->call([new SendMessageJob($messageId, $attempt), 'handle']);
}

function brechenderTransport(): void
{
    $mailer = Mockery::mock(BrandMailer::class);
    $mailer->shouldReceive('send')->andThrow(new TransportException('Connection to smtp could not be established'));
    app()->instance(BrandMailer::class, $mailer);
}

it('keeps a delivered message as sent when a step after the send throws', function (): void {
    Exceptions::fake();

    Event::listen(MessageSent::class, function (): void {
        throw new RuntimeException('listener broke after the send');
    });

    stillSenden($this->message->id);

    expect(stillRausgegangen())->toBe(1)
        ->and($this->message->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and($this->message->fresh()->sent_at)->not->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'listener broke after the send');
});

it('puts a message back with a delay when the transport fails', function (): void {
    Queue::fake();
    config()->set('queue.default', 'database');
    brechenderTransport();

    stillSenden($this->message->id);

    $fresh = $this->message->fresh();

    expect($fresh->status)->toBe(Message::STATUS_PENDING)
        ->and($fresh->claimed_at)->toBeNull()
        ->and($fresh->sent_at)->toBeNull()
        ->and(app(CampaignRepository::class)->find('stille-kampagne')->status)->toBe(Campaign::STATUS_SENDING);

    Queue::assertPushed(SendMessageJob::class, fn (SendMessageJob $job) => $job->messageId === $this->message->id
        && $job->transportAttempt === 2
        && $job->delay !== null);
});

it('fails a message for good after the last transport attempt and reports the count when the campaign finishes', function (): void {
    Queue::fake();
    Event::fake([CampaignSent::class]);
    Log::spy();
    config()->set('queue.default', 'database');
    config()->set('marketing.sending.transport_attempts', 3);
    brechenderTransport();

    stillSenden($this->message->id, 3);

    expect($this->message->fresh()->status)->toBe(Message::STATUS_FAILED);

    Queue::assertNotPushed(SendMessageJob::class);

    Event::assertDispatched(CampaignSent::class, fn (CampaignSent $e) => ($e->metadata['failed'] ?? null) === 1);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $line) => str_contains($line, 'stille-kampagne') && str_contains($line, '1 of 1')
    )->once();
});

it('fails at once on the sync queue, where there is no later to retry in', function (): void {
    config()->set('queue.default', 'sync');
    brechenderTransport();

    stillSenden($this->message->id);

    expect($this->message->fresh()->status)->toBe(Message::STATUS_FAILED);
});

it('does not send to a contact who opted out after the audience was snapshotted', function (): void {
    Contact::query()->where('uuid', $this->recipient->contact_uuid)->update(['do_not_contact' => true]);

    stillSenden($this->message->id);

    expect(stillRausgegangen())->toBe(0)
        ->and($this->message->fresh()->status)->toBe(Message::STATUS_SKIPPED);
});

it('does not send when no LeadHub contact can be found to ask (fail closed)', function (): void {
    Contact::query()->where('uuid', $this->recipient->contact_uuid)->delete();

    stillSenden($this->message->id);

    expect(stillRausgegangen())->toBe(0)
        ->and($this->message->fresh()->status)->toBe(Message::STATUS_SKIPPED);
});
