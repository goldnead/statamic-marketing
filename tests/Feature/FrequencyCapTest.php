<?php

use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Jobs\SendMessageJob;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\MailLogEntry;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Contracts\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;

/**
 * The frequency cap.
 *
 * Four of these are named in the specification: one per exception (digest,
 * transactional, reminder) and one proving the decision is taken at the moment
 * of sending rather than at the moment of queueing. The rest hold the
 * properties those four depend on — above all that the whole thing is off until
 * somebody turns it on.
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

    $this->campaignOfClass = function (string $class, string $handle = 'issue'): Campaign {
        app(CampaignRepository::class)->save(new Campaign(
            handle: $handle,
            name: 'Issue',
            subject: 'Hello',
            listHandle: 'newsletter',
            content: '<p>Hi.</p>',
            status: Campaign::STATUS_SENDING,
            mailClass: $class,
        ));

        return app(CampaignRepository::class)->find($handle);
    };

    $this->pendingMessage = function (string $campaignHandle = 'issue'): Message {
        return Message::query()->create([
            'campaign_handle' => $campaignHandle,
            'subscription_id' => $this->jane->id,
            'email' => $this->jane->email,
            'status' => Message::STATUS_PENDING,
        ]);
    };

    $this->runJob = function (Message $message): void {
        (new SendMessageJob($message->id))->handle(
            app(CampaignRepository::class),
            app(MailingListRepository::class),
            app(CampaignRenderer::class),
            app(Gate::class),
            app(FrequencyCap::class),
        );
    };

    // Put somebody at the limit, without going through a send.
    $this->fillTheWindow = function (int $count, string $class = 'marketing', ?string $when = null): void {
        foreach (range(1, $count) as $i) {
            MailLogEntry::query()->create([
                'brand_id' => app('brand-context')->currentId(),
                'email_normalized' => 'jane@example.com',
                'mail_class' => $class,
                'reference' => 'earlier',
                'sent_at' => $when ? now()->parse($when) : now(),
            ]);
        }
    };

    $this->enableCap = function (int $max = 3, int $windowHours = 168): void {
        config()->set('marketing.frequency_cap.enabled', true);
        config()->set('marketing.frequency_cap.max', $max);
        config()->set('marketing.frequency_cap.window_hours', $windowHours);
    };

    // A real queue, which is what deferring needs. The suite otherwise runs on
    // `sync`, where a dispatch executes inline and a delay is ignored — so
    // without this, "put this message back for a day" would re-enter the job
    // immediately and the deferral budget would be spent inside one call. That
    // is a real property of a sync install and it has a test of its own below;
    // it is simply not the property these cases are about.
    $this->withAQueue = function (): void {
        config()->set('queue.default', 'database');
        Queue::fake();
    };
});

it('is switched off by default, so updating the package changes no send', function (): void {
    // The one property that makes this feature safe to ship into a running
    // install. Read from the shipped config file, not from a value a test set.
    $shipped = require dirname(__DIR__, 2).'/config/marketing.php';

    expect($shipped['frequency_cap']['enabled'])->toBeFalse();

    ($this->campaignOfClass)(MailClass::Marketing->value);
    ($this->fillTheWindow)(50);

    ($this->runJob)($message = ($this->pendingMessage)());

    Mail::assertSent(CampaignMail::class, 1);
    expect($message->fresh()->status)->toBe(Message::STATUS_SENT);
});

it('defaults an unclassified campaign to marketing', function (): void {
    // The conservative direction: forgetting to classify costs a delay, never
    // an exemption nobody asked for.
    expect((new Campaign(handle: 'x', name: 'X'))->mailClass())->toBe(MailClass::Marketing)
        ->and(MailClass::fromValue(null))->toBe(MailClass::Marketing)
        ->and(MailClass::fromValue(''))->toBe(MailClass::Marketing)
        ->and(MailClass::fromValue('something-from-a-later-release'))->toBe(MailClass::Marketing);
});

it('defers a marketing mail to a contact who is already at the limit', function (): void {
    ($this->enableCap)(max: 3);
    ($this->campaignOfClass)(MailClass::Marketing->value);
    ($this->fillTheWindow)(3);
    ($this->withAQueue)();

    ($this->runJob)($message = ($this->pendingMessage)());

    Mail::assertNothingSent();

    // Moved, not dropped: the same message goes back on the queue.
    Queue::assertPushed(SendMessageJob::class);

    $message = $message->fresh();

    // Still pending, which is load-bearing: `maybeFinalize()` marks a campaign
    // sent once nothing is pending, and a deferred message under any other
    // status would let the campaign report itself finished while somebody was
    // still waiting for it.
    expect($message->status)->toBe(Message::STATUS_PENDING)
        ->and($message->cap_deferrals)->toBe(1)
        ->and($message->cap_deferred_until)->not->toBeNull();

    expect(app(CampaignRepository::class)->find('issue')->status)->toBe(Campaign::STATUS_SENDING);
});

it('discards and logs a message once the deferral budget runs out', function (): void {
    ($this->enableCap)(max: 3);
    config()->set('marketing.frequency_cap.defer.max_deferrals', 2);
    ($this->campaignOfClass)(MailClass::Marketing->value);
    ($this->fillTheWindow)(3);
    ($this->withAQueue)();

    $message = ($this->pendingMessage)();

    foreach (range(1, 3) as $attempt) {
        ($this->runJob)($message);
        $message = $message->fresh();
    }

    Mail::assertNothingSent();

    // Discarded, but not quietly. `capped` is a different word from `skipped`
    // on purpose: skipped means the address may not be mailed, capped means it
    // may and was not — and somebody asking why the March issue never arrived
    // needs those two answers to be different.
    expect($message->status)->toBe(Message::STATUS_CAPPED)
        ->and($message->cap_deferrals)->toBe(2)
        ->and($message->error)->toContain('Frequency cap');

    // Nothing is left pending, so the campaign can finish.
    expect(app(CampaignRepository::class)->find('issue')->status)->toBe(Campaign::STATUS_SENT);
});

it('sends and counts a marketing mail while the contact is under the limit', function (): void {
    ($this->enableCap)(max: 3);
    ($this->campaignOfClass)(MailClass::Marketing->value);
    ($this->fillTheWindow)(2);

    ($this->runJob)($message = ($this->pendingMessage)());

    Mail::assertSent(CampaignMail::class, 1);

    expect($message->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and(app(FrequencyCap::class)->countInWindow('jane@example.com'))->toBe(3);
});

/*
 * The three exceptions. They are the actual content of the rule: an upper bound
 * that also applied to a password reset would be a support ticket, and one that
 * counted the digest somebody subscribed to would let the digest eat the whole
 * budget and silence everything else.
 */

it('does not count or hold a community digest', function (): void {
    ($this->enableCap)(max: 3);
    ($this->campaignOfClass)(MailClass::Digest->value);
    ($this->fillTheWindow)(3);

    ($this->runJob)($message = ($this->pendingMessage)());

    Mail::assertSent(CampaignMail::class, 1);
    expect($message->fresh()->status)->toBe(Message::STATUS_SENT);

    // Recorded — the log is also what the control panel reads — but not
    // counted, so it did not consume anybody's marketing budget.
    expect(MailLogEntry::query()->where('mail_class', 'digest')->count())->toBe(1)
        ->and(app(FrequencyCap::class)->countInWindow('jane@example.com'))->toBe(3);

    expect(MailClass::Digest->countsTowardCap())->toBeFalse()
        ->and(MailClass::Digest->subjectToCap())->toBeFalse();
});

it('does not count or hold a transactional mail', function (): void {
    ($this->enableCap)(max: 3);
    ($this->campaignOfClass)(MailClass::Transactional->value);
    ($this->fillTheWindow)(3);

    ($this->runJob)($message = ($this->pendingMessage)());

    Mail::assertSent(CampaignMail::class, 1);
    expect($message->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and(app(FrequencyCap::class)->countInWindow('jane@example.com'))->toBe(3);

    expect(MailClass::Transactional->countsTowardCap())->toBeFalse()
        ->and(MailClass::Transactional->subjectToCap())->toBeFalse();
});

it('lets an event reminder exceed the cap', function (): void {
    ($this->enableCap)(max: 3);
    ($this->campaignOfClass)(MailClass::Reminder->value);
    // Far past the limit, not merely at it: a reminder is allowed through
    // whatever the count says.
    ($this->fillTheWindow)(10);

    ($this->runJob)($message = ($this->pendingMessage)());

    Mail::assertSent(CampaignMail::class, 1);
    expect($message->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and($message->fresh()->cap_deferrals)->toBe(0);

    expect(MailClass::Reminder->subjectToCap())->toBeFalse();
});

/*
 * When the question is asked. A job can sit in the queue for days behind a
 * throttle, a retry or a stopped worker, so the answer has to be about the
 * window that ends now — not the one that ended when the audience was built.
 */

it('decides at the moment of sending, not the moment of queueing', function (): void {
    ($this->enableCap)(max: 3);
    ($this->campaignOfClass)(MailClass::Marketing->value);

    // Nothing in the window: at ENQUEUE time this recipient is well under the
    // cap, and a decision taken here would let the mail through.
    ($this->withAQueue)();

    expect(app(FrequencyCap::class)->allows('jane@example.com', MailClass::Marketing))->toBeTrue();

    $message = ($this->pendingMessage)();

    SendMessageJob::dispatch($message->id);
    Queue::assertPushed(SendMessageJob::class);

    // Three days pass in the queue, and three other marketing mails go out.
    $this->travel(3)->days();
    ($this->fillTheWindow)(3);

    ($this->runJob)($message);

    Mail::assertNothingSent();
    expect($message->fresh()->status)->toBe(Message::STATUS_PENDING)
        ->and($message->fresh()->cap_deferrals)->toBe(1);
});

it('lets a message through whose window has emptied while it waited', function (): void {
    // The same property from the other side, and the one a naive
    // check-at-enqueue implementation also gets wrong: over the limit when the
    // job was created, under it by the time the job runs.
    ($this->enableCap)(max: 3, windowHours: 168);
    ($this->campaignOfClass)(MailClass::Marketing->value);
    ($this->fillTheWindow)(3);

    $message = ($this->pendingMessage)();

    expect(app(FrequencyCap::class)->allows('jane@example.com', MailClass::Marketing))->toBeFalse();

    // Eight days later those three have rolled out of the seven-day window.
    $this->travel(8)->days();

    ($this->runJob)($message);

    Mail::assertSent(CampaignMail::class, 1);
    expect($message->fresh()->status)->toBe(Message::STATUS_SENT);
});

it('counts one person once, however many lists they are on', function (): void {
    // The address is the identity, not the subscription. Counting per list
    // would hand somebody on four lists four times the cap while the config
    // still said three.
    ($this->enableCap)(max: 3);

    app(MailingListRepository::class)->save(new MailingList(handle: 'second', name: 'Second', doubleOptIn: false));
    app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('second'),
        'JANE@Example.com',
    );

    ($this->fillTheWindow)(3);

    // Same person, different casing, different list — same count.
    expect(app(FrequencyCap::class)->countInWindow('JANE@Example.com'))->toBe(3)
        ->and(app(FrequencyCap::class)->allows('JANE@Example.com', MailClass::Marketing))->toBeFalse();
});

it('measures the rolling window correctly when the application is not on UTC', function (): void {
    // Laravel's `datetime` cast writes a zoned Carbon out without converting
    // it, so a value that crosses a timezone on the way into the table lands
    // off by that offset and a window built on it is wrong at both edges by the
    // same amount. Nothing here crosses one: `now()` writes and `now()` reads.
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    ($this->enableCap)(max: 3, windowHours: 24);

    ($this->fillTheWindow)(3);

    expect(app(FrequencyCap::class)->countInWindow('jane@example.com'))->toBe(3);

    // 23 hours later they are still inside a 24-hour window…
    $this->travel(23)->hours();
    expect(app(FrequencyCap::class)->countInWindow('jane@example.com'))->toBe(3);

    // …and two hours after that they are outside it. An hour of drift from a
    // dropped offset would put the boundary in the wrong place here.
    $this->travel(2)->hours();
    expect(app(FrequencyCap::class)->countInWindow('jane@example.com'))->toBe(0);
});

it('never records a mail that failed to send', function (): void {
    ($this->enableCap)(max: 3);
    ($this->campaignOfClass)(MailClass::Marketing->value);

    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('transport down'));

    ($this->runJob)($message = ($this->pendingMessage)());

    expect($message->fresh()->status)->toBe(Message::STATUS_FAILED)
        // A mail that reached nobody may not consume anybody's budget.
        ->and(MailLogEntry::query()->count())->toBe(0);
});

it('falls open rather than stopping a send it cannot count', function (): void {
    // The deliberate opposite of the suppression gate, which falls closed and
    // aborts. This one protects attention, not consent: refusing to send
    // because a count failed would trade a real delivery failure for a
    // hypothetical annoyance.
    ($this->enableCap)(max: 1);
    ($this->fillTheWindow)(5);

    Schema::drop('marketing_mail_log');

    expect(app(FrequencyCap::class)->countInWindow('jane@example.com'))->toBe(0)
        ->and(app(FrequencyCap::class)->allows('jane@example.com', MailClass::Marketing))->toBeTrue();
});

it('discards rather than pretending to defer when there is no queue', function (): void {
    // On the `sync` connection a dispatch runs inline and a delay is ignored,
    // so "come back tomorrow" would be "come back now", three times, inside one
    // request. Discarding once and naming the reason is the honest version, and
    // it keeps the campaign from stalling in `sending` for ever.
    config()->set('queue.default', 'sync');

    ($this->enableCap)(max: 1);
    ($this->campaignOfClass)(MailClass::Marketing->value);
    ($this->fillTheWindow)(1);

    ($this->runJob)($message = ($this->pendingMessage)());

    Mail::assertNothingSent();

    $message = $message->fresh();

    expect($message->status)->toBe(Message::STATUS_CAPPED)
        ->and($message->cap_deferrals)->toBe(0)
        ->and($message->error)->toContain('sync')
        ->and(app(CampaignRepository::class)->find('issue')->status)->toBe(Campaign::STATUS_SENT);
});

it('counts only the current brand in the control panel column', function (): void {
    // The column exists to explain a hold, so it has to show the number the cap
    // actually acted on. The cap always filters by brand; this query did not,
    // so an address that exists in two brands was shown a sum that had never
    // been used for any decision.
    ($this->enableCap)(max: 3);

    ($this->fillTheWindow)(2);

    // The same address, one marketing mail, in a brand that is not the current
    // one. It may not appear in this brand's count.
    MailLogEntry::query()->create([
        'brand_id' => app('brand-context')->currentId() + 99,
        'email_normalized' => 'jane@example.com',
        'mail_class' => 'marketing',
        'reference' => 'other-brand',
        'sent_at' => now(),
    ]);

    $editor = User::make()->email('cap-editor@example.com')->makeSuper();
    $editor->save();

    $this->actingAs($editor);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.lists.show', 'newsletter'));

    $subscribers = json_decode($response->getContent(), true)['props']['subscribers'];
    $jane = collect($subscribers)->firstWhere('email', 'jane@example.com');

    expect($jane['frequency']['sent'])->toBe(2)
        // …and it agrees with the service, which is the whole point.
        ->and($jane['frequency']['sent'])->toBe(app(FrequencyCap::class)->countInWindow('jane@example.com'));
});

it('exposes the classification and the cap through the container, not a slug', function (): void {
    // Bound under the contract's own class name. A short string key would sit
    // next to the framework's aliases, which is how a sibling in this family
    // overwrote Laravel's event dispatcher.
    expect(app(FrequencyCap::class))->toBeInstanceOf(FrequencyCap::class);

    expect(MailClass::values())->toBe(['marketing', 'transactional', 'digest', 'reminder']);
});
