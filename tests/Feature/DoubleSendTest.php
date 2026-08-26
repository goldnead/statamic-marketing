<?php

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Jobs\SendMessageJob;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignReport;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\User;

/*
 * One recipient, one mail — whatever the workers do.
 *
 * The guard used to be `if ($message->status !== 'pending') return;`: a read,
 * then a send, then a write. Two workers read before either wrote, both passed,
 * both sent. This was reproduced before it was fixed, and the reproduction is
 * the first test here.
 *
 * NOTE: none of these may use Mail::fake(). The fake does not dispatch
 * MessageSending, so the interleaving below would never be triggered and the
 * test would pass without having tested anything — green for the wrong reason,
 * which on this particular bug is exactly how it survived.
 */
beforeEach(function (): void {
    $this->user = User::make()->email('editor@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('mail.default', 'array');

    app(MailingListRepository::class)->save(
        new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false)
    );
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'test-kampagne',
        name: 'Test',
        subject: 'Hallo',
        listHandle: 'newsletter',
        content: '<p>Hallo.</p>',
    ));

    $this->recipient = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'empfaenger@example.com',
    );
});

function nachricht(int $subscriptionId): Message
{
    return Message::create([
        'campaign_handle' => 'test-kampagne',
        'subscription_id' => $subscriptionId,
        'email' => 'empfaenger@example.com',
        'status' => Message::STATUS_PENDING,
    ]);
}

function rausgegangen(): int
{
    return count(Mail::mailer()->getSymfonyTransport()->messages());
}

it('sends once when two workers take the same message at the same moment', function (): void {
    $message = nachricht($this->recipient->id);

    // The real interleaving: B starts while A is inside the send and has not
    // yet written the status. That is what a second worker sees, and what a
    // retry after a crash sees.
    $b = app(SendMessageJob::class, ['messageId' => $message->id]);
    $einmal = false;

    Event::listen(MessageSending::class, function (MessageSending $e) use (&$einmal, $b): void {
        if ($einmal) {
            return;
        }
        $einmal = true;
        app()->call([$b, 'handle']);
    });

    app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);

    expect($einmal)->toBeTrue('die Verschraenkung muss wirklich stattgefunden haben')
        ->and(rausgegangen())->toBe(1)
        ->and($message->fresh()->status)->toBe(Message::STATUS_SENT);
});

it('sends once when the same job is retried after it already delivered', function (): void {
    $message = nachricht($this->recipient->id);

    app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);
    app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);

    expect(rausgegangen())->toBe(1);
});

/*
 * The other half of the constraint. A claim that cannot be given back turns
 * "one mail twice" into "no mail at all", which is the worse of the two: a
 * duplicate gets complained about, a missing newsletter does not.
 */
it('hands the claim back when the job throws before it writes anything', function (): void {
    $message = nachricht($this->recipient->id);

    // A lookup between the claim and the first status write fails — the
    // suppression backend is down, the repository cannot reach its store.
    app()->bind(FrequencyCap::class, fn () => new class implements FrequencyCap
    {
        public function enabled(): bool
        {
            return true;
        }

        public function limit(): int
        {
            return 1;
        }

        public function windowHours(): int
        {
            return 24;
        }

        public function allows(string $email, MailClass $class, ?int $brandId = null): bool
        {
            throw new RuntimeException('Backend nicht erreichbar');
        }

        public function record(string $email, MailClass $class, ?int $brandId = null, ?string $reference = null): void {}

        public function countInWindow(string $email, ?int $brandId = null): int
        {
            return 0;
        }
    });

    try {
        app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);
    } catch (RuntimeException) {
        // The throw is the point: the queue has to see it and retry.
    }

    expect($message->fresh()->status)->toBe(Message::STATUS_PENDING, 'sonst kann sie niemand mehr beanspruchen')
        ->and($message->fresh()->claimed_at)->toBeNull()
        ->and(Message::claim($message->id))->toBeTrue('der Wiederholungsversuch muss drankommen');
});

it('hands the claim back when the job finally fails', function (): void {
    $message = nachricht($this->recipient->id);
    Message::claim($message->id);

    app(SendMessageJob::class, ['messageId' => $message->id])->failed(new RuntimeException('aufgegeben'));

    expect($message->fresh()->status)->toBe(Message::STATUS_PENDING);
});

/*
 * The sweeper's own trap, found by review and reproduced: a worker killed AFTER
 * the handover leaves the row in `sending`, and re-queueing it puts a second
 * copy in a real inbox.
 */
it('does not re-send a message that was already handed to the transport', function (): void {
    $message = nachricht($this->recipient->id);
    app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);

    expect(rausgegangen())->toBe(1);

    // Exactly the state a kill between handover and status write leaves behind:
    // sent_at stamped, status still sending, lease long expired.
    $message->fresh()->update([
        'status' => Message::STATUS_SENDING,
        'claimed_at' => now()->subHours(2),
    ]);

    $this->artisan('marketing:release-stale-sends')
        ->expectsOutputToContain('ohne erneuten Versand')
        ->assertSuccessful();

    expect(rausgegangen())->toBe(1, 'kein zweites Exemplar an eine echte Adresse')
        ->and($message->fresh()->status)->toBe(Message::STATUS_SENT);
});

it('counts a message in flight, so the report does not lose people', function (): void {
    $message = nachricht($this->recipient->id);
    Message::claim($message->id);

    $stats = app(CampaignStats::class)->forCampaign(
        app(CampaignRepository::class)->find('test-kampagne')
    );

    // The promise the class makes about itself: the statuses add up to
    // `recipients`. A state nobody counts breaks it silently.
    $summe = $stats['sent'] + $stats['failed'] + $stats['skipped']
        + $stats['capped'] + $stats['pending'] + $stats['sending'] + $stats['bounced'];

    expect($stats['sending'])->toBe(1)
        ->and($summe)->toBe($stats['recipients']);
});

it('offers every storable message status as a report filter', function (): void {
    // The delivery tab filters on this list. A status missing from it is a
    // status nobody can look for — and the one you would most want to look
    // for is the one that got stuck.
    $gespeichert = [Message::STATUS_PENDING, Message::STATUS_SENDING, Message::STATUS_SENT,
        Message::STATUS_FAILED, Message::STATUS_SKIPPED, Message::STATUS_BOUNCED,
        Message::STATUS_CAPPED];

    expect(array_values(CampaignReport::STATUSES))->toEqualCanonicalizing($gespeichert);
});

it('has a label for every message status it can store', function (): void {
    foreach ([Message::STATUS_PENDING, Message::STATUS_SENDING, Message::STATUS_SENT,
        Message::STATUS_FAILED, Message::STATUS_SKIPPED, Message::STATUS_BOUNCED,
        Message::STATUS_CAPPED] as $status) {
        $key = "marketing::campaigns.message_statuses.{$status}";

        // A missing key renders as the key itself — the badge in the delivery
        // tab would read `marketing::campaigns.message_statuses.sending`.
        expect(__($key))->not->toBe($key, "Beschriftung fehlt für [{$status}]");
    }
});

it('marks a claimed message as still outstanding, so the campaign cannot finish early', function (): void {
    $message = nachricht($this->recipient->id);

    expect(Message::claim($message->id))->toBeTrue()
        ->and($message->fresh()->status)->toBe(Message::STATUS_SENDING)
        // Load-bearing: maybeFinalize() marks a campaign sent once nothing is
        // pending. A message in flight that stopped counting would let the
        // campaign report itself complete while somebody was still waiting.
        ->and(Message::forCampaign('test-kampagne')->pending()->count())->toBe(1);
});

it('lets only the first of two claims through', function (): void {
    $message = nachricht($this->recipient->id);

    expect(Message::claim($message->id))->toBeTrue()
        ->and(Message::claim($message->id))->toBeFalse();
});

it('hands back a message whose worker never came home', function (): void {
    $message = nachricht($this->recipient->id);
    Message::claim($message->id);

    // Older than the lease: the worker is gone.
    $message->fresh()->update(['claimed_at' => now()->subHours(2)]);

    $this->artisan('marketing:release-stale-sends')
        ->expectsOutputToContain('erneut zugestellt')
        ->assertSuccessful();

    // NOT asserted as `pending`. The release re-dispatches, and on the `sync`
    // connection the tests run on that happens inline — so by the time this
    // line reads the row, the mail has already gone out. Asserting the
    // in-between state would pin an artefact of the queue driver rather than
    // the behaviour: the message is no longer stuck, and it was delivered.
    // `claimed_at` stays set, and stays set on purpose: it is when delivery
    // was picked up, which is worth keeping. The sweeper only ever looks at
    // rows still in `sending`, so a stamp on a finished row cannot mislead it.
    expect($message->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and(rausgegangen())->toBe(1);
});

it('leaves a message alone while its worker is still within the lease', function (): void {
    $message = nachricht($this->recipient->id);
    Message::claim($message->id);

    // Releasing early would re-create the very bug the claim exists for: the
    // first worker is still sending, and a second one would be handed the
    // same message.
    $this->artisan('marketing:release-stale-sends')
        ->expectsOutputToContain('Nichts hängt fest')
        ->assertSuccessful();

    expect($message->fresh()->status)->toBe(Message::STATUS_SENDING);
});

it('does not send twice when a stale message is released and picked up again', function (): void {
    $message = nachricht($this->recipient->id);

    app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);
    expect(rausgegangen())->toBe(1);

    // A sweeper that could pull a finished message back would be worse than
    // the bug it cleans up after.
    $this->artisan('marketing:release-stale-sends')->assertSuccessful();

    expect($message->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and(rausgegangen())->toBe(1);
});

/*
 * The send window, at the one place it can be observed: the job.
 *
 * A rule that only the support class knows would be the same shape of nothing
 * as a custom field nobody can segment on.
 */
it('holds a message back until the recipients window opens', function (): void {
    config()->set('marketing.sending.window', ['from' => 8, 'to' => 20, 'timezone' => null]);
    // A real connection name the test environment has, and Queue::fake() so
    // the deferred dispatch is captured instead of needing a jobs table.
    config()->set('queue.default', 'database');
    Queue::fake();

    $this->recipient->update(['timezone' => 'Europe/Berlin']);
    $message = nachricht($this->recipient->id);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 01:40', 'UTC'));

    app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);

    CarbonImmutable::setTestNow();

    // Re-queued for the morning, not dropped.
    Queue::assertPushed(SendMessageJob::class);

    // Nothing went out, and the claim went back — a message waiting for
    // tomorrow morning still has to count as outstanding, or the campaign
    // reports itself finished while somebody is still waiting for it.
    expect(rausgegangen())->toBe(0)
        ->and($message->fresh()->status)->toBe(Message::STATUS_PENDING)
        ->and($message->fresh()->claimed_at)->toBeNull()
        ->and(Message::forCampaign('test-kampagne')->pending()->count())->toBe(1);
});

it('sends straight away inside the window', function (): void {
    config()->set('marketing.sending.window', ['from' => 8, 'to' => 20, 'timezone' => null]);
    config()->set('queue.default', 'array');

    $this->recipient->update(['timezone' => 'Europe/Berlin']);
    $message = nachricht($this->recipient->id);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 12:00', 'UTC'));

    app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);

    CarbonImmutable::setTestNow();

    expect(rausgegangen())->toBe(1)
        ->and($message->fresh()->status)->toBe(Message::STATUS_SENT);
});

it('sends anyway on an installation with no queue to come back with', function (): void {
    config()->set('marketing.sending.window', ['from' => 8, 'to' => 20, 'timezone' => null]);
    config()->set('queue.default', 'sync');

    $this->recipient->update(['timezone' => 'Europe/Berlin']);
    $message = nachricht($this->recipient->id);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 01:40', 'UTC'));

    app()->call([app(SendMessageJob::class, ['messageId' => $message->id]), 'handle']);

    CarbonImmutable::setTestNow();

    // On `sync` a delay is ignored and there is no worker to come back later.
    // A message that silently never sends is worse than one that arrives at an
    // odd hour.
    expect(rausgegangen())->toBe(1);
});
