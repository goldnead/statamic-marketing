<?php

/**
 * The winner send of an A/B test.
 *
 * `ab_share` 10-50: the test runs on that share of the audience, split half and
 * half between the two subjects; after the wait the variant with the higher
 * open rate goes to everybody else. Tie or too small a sample: variant A, with
 * the reason written down.
 */

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Jobs\SendMessageJob;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\AbDecision;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Services\AbTest;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Services\VariantAssigner;
use Goldnead\Suppression\Contracts\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

const AB_SHARE = 50;

beforeEach(function (): void {
    Mail::fake();

    config()->set('marketing.ab.wait_minutes', 240);
    config()->set('marketing.ab.min_per_variant', 1);

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
});

/**
 * Subscribe somebody whose uuid puts them in the test group (or outside it),
 * and, inside it, in the given variant. The uuid is the only recipient-side
 * input of both assignments, so choosing it names the bucket without
 * re-implementing the hash.
 */
function abSubscriber(string $email, bool $inTest, ?string $variant = null): object
{
    $subscription = app(SubscriptionService::class)->subscribe(test()->list, $email, ['first_name' => 'X']);
    $brandId = $subscription->brand_id === null ? null : (int) $subscription->brand_id;

    do {
        $uuid = (string) Str::uuid();
        $ok = app(AbTest::class)->inTestGroup('juli', $uuid, $brandId, AB_SHARE) === $inTest
            && ($variant === null || app(VariantAssigner::class)->assign('juli', $uuid, $brandId) === $variant);
    } while (! $ok);

    $subscription->forceFill(['uuid' => $uuid])->save();

    return $subscription->fresh();
}

function abCampaign(int $share = AB_SHARE, ?string $variantSubject = 'Betreff B'): Campaign
{
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'juli',
        name: 'Juli',
        subject: 'Betreff A',
        variantSubject: $variantSubject,
        listHandle: 'newsletter',
        content: '<p>Hallo</p>',
        abShare: $share,
    ));

    return app(CampaignRepository::class)->find('juli');
}

/** Two in the test group (one per variant), two outside it. */
function abAudience(): array
{
    return [
        'ta' => abSubscriber('ta@example.com', true, 'a'),
        'tb' => abSubscriber('tb@example.com', true, 'b'),
        'r1' => abSubscriber('r1@example.com', false),
        'r2' => abSubscriber('r2@example.com', false),
    ];
}

function abOpen(string $email): void
{
    $message = Message::forCampaign('juli')->where('email', $email)->firstOrFail();
    $message->forceFill(['status' => Message::STATUS_SENT])->save();
    MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_OPEN, 'machine' => false]);
}

function abState(): array
{
    return app(CampaignRepository::class)->find('juli')->meta['ab'] ?? [];
}

function abStartJob(bool $winnerPass = false): void
{
    (new StartCampaignJob('juli', $winnerPass))->handle(
        app(CampaignRepository::class),
        app(ContactRepository::class),
        app(Gate::class),
        app(VariantAssigner::class),
    );
}

it('sends the test only to the test share and keeps the campaign open', function (): void {
    abAudience();

    app(CampaignSender::class)->queue(abCampaign());

    expect(Message::forCampaign('juli')->pluck('email')->sort()->values()->all())
        ->toBe(['ta@example.com', 'tb@example.com']);

    Mail::assertSent(CampaignMail::class, 2);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('ta@example.com') && $m->rendered->subject === 'Betreff A');
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('tb@example.com') && $m->rendered->subject === 'Betreff B');

    $campaign = app(CampaignRepository::class)->find('juli');

    expect($campaign->status)->toBe(Campaign::STATUS_SENDING)
        ->and(abState()['phase'])->toBe('testing')
        ->and(abState()['test_finished_at'])->not->toBeNull();
});

it('does nothing before the wait is over', function (): void {
    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(239)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    Mail::assertSent(CampaignMail::class, 2);
    expect(abState()['phase'])->toBe('testing');
});

it('sends the winner to the rest after the wait and finishes the campaign', function (): void {
    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(241)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    Mail::assertSent(CampaignMail::class, 4);
    foreach (['r1@example.com', 'r2@example.com'] as $email) {
        Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo($email) && $m->rendered->subject === 'Betreff B');
    }

    expect(abState())->toMatchArray(['phase' => 'winner', 'winner' => 'b', 'reason' => 'higher_open_rate'])
        ->and(app(CampaignRepository::class)->find('juli')->status)->toBe(Campaign::STATUS_SENT)
        ->and(Message::forCampaign('juli')->where('email', 'r1@example.com')->value('variant'))->toBe('b');
});

it('sends no recipient twice, however often the winner is triggered', function (): void {
    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('ta@example.com');

    $this->travel(241)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    abStartJob(true);
    abStartJob();

    Mail::assertSent(CampaignMail::class, 4);
    expect(Message::forCampaign('juli')->count())->toBe(4)
        ->and(Message::forCampaign('juli')->distinct('email')->count('email'))->toBe(4);
});

it('finishes a winner pass that a dead worker left half done, without a second mail', function (): void {
    $audience = abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(241)->minutes();

    // The worker died after writing r1's row, before sending it.
    app(AbTest::class)->decide(app(CampaignRepository::class), app(CampaignRepository::class)->find('juli'));
    Message::create([
        'campaign_handle' => 'juli',
        'subscription_id' => $audience['r1']->id,
        'email' => 'r1@example.com',
        'status' => Message::STATUS_PENDING,
        'variant' => 'b',
    ]);

    abStartJob(true);
    abStartJob(true);

    Mail::assertSent(CampaignMail::class, 4);
    expect(Message::forCampaign('juli')->count())->toBe(4)
        ->and(Message::forCampaign('juli')->where('status', Message::STATUS_SENT)->count())->toBe(4);
});

it('leaves out somebody who unsubscribed during the wait', function (): void {
    $audience = abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    app(SubscriptionService::class)->unsubscribe($audience['r2']);

    $this->travel(241)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    Mail::assertSent(CampaignMail::class, 3);
    Mail::assertNotSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('r2@example.com'));
});

it('picks variant A on a tie and says so', function (): void {
    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('ta@example.com');
    abOpen('tb@example.com');

    $this->travel(241)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    expect(abState())->toMatchArray(['winner' => 'a', 'reason' => 'tie']);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('r1@example.com') && $m->rendered->subject === 'Betreff A');
});

it('picks variant A when the sample is too small, even if B opened more', function (): void {
    config()->set('marketing.ab.min_per_variant', 5);

    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(241)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    expect(abState())->toMatchArray(['winner' => 'a', 'reason' => 'sample_too_small']);
});

it('reads the wait from the config', function (): void {
    config()->set('marketing.ab.wait_minutes', 30);

    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(31)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    Mail::assertSent(CampaignMail::class, 4);
});

it('does not count a machine open as a win', function (): void {
    abAudience();
    app(CampaignSender::class)->queue(abCampaign());

    $message = Message::forCampaign('juli')->where('email', 'tb@example.com')->firstOrFail();
    MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_OPEN, 'machine' => true]);

    $this->travel(241)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    expect(abState()['reason'])->toBe('tie');
});

it('a restarted test pass does not duplicate or reassign anything', function (): void {
    abAudience();
    app(CampaignSender::class)->queue(abCampaign());

    $before = Message::forCampaign('juli')->orderBy('id')->pluck('variant', 'id')->all();

    abStartJob();

    expect(Message::forCampaign('juli')->orderBy('id')->pluck('variant', 'id')->all())->toBe($before);
    Mail::assertSent(CampaignMail::class, 2);
    expect(abState()['phase'])->toBe('testing');
});

it('leaves a campaign without a test share exactly as it was', function (): void {
    abAudience();

    app(CampaignSender::class)->queue(abCampaign(0));

    Mail::assertSent(CampaignMail::class, 4);
    expect(app(CampaignRepository::class)->find('juli')->status)->toBe(Campaign::STATUS_SENT)
        ->and(abState())->toBe([]);

    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 4);
});

it('ignores a test share without a second subject', function (): void {
    abAudience();

    app(CampaignSender::class)->queue(abCampaign(AB_SHARE, null));

    Mail::assertSent(CampaignMail::class, 4);
    expect(abState())->toBe([]);
});

it('lists the unsent campaigns whose behaviour this feature changes', function (): void {
    abCampaign();

    $this->artisan('marketing:ab-campaigns')
        ->expectsOutputToContain('juli')
        ->expectsOutputToContain('1 campaign(s)')
        ->assertSuccessful();

    abCampaign(0);

    $this->artisan('marketing:ab-campaigns')->expectsOutputToContain('0 campaign(s)')->assertSuccessful();
});

/**
 * The decision is a claim in the database, not a cache lock. Two hosts, or a
 * cache that cannot lock, must still produce exactly one winner pass.
 */
it('decides once even when the cache lock cannot hold anybody back', function (): void {
    config()->set('cache.default', 'null');

    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(241)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    Mail::assertSent(CampaignMail::class, 4);
    expect(Message::forCampaign('juli')->count())->toBe(4)
        ->and(AbDecision::query()->count())->toBe(1);
});

it('does not queue a winner that another host already claimed', function (): void {
    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(241)->minutes();

    // The other host claimed the decision and has not written the campaign yet.
    AbDecision::create([
        'claim_key' => app(AbTest::class)->claimKey('juli'),
        'campaign_handle' => 'juli',
        'winner' => 'b',
        'reason' => 'higher_open_rate',
        'results' => [],
        'decided_at' => now(),
        'dispatched_at' => now(),
    ]);

    $this->artisan('marketing:send-ab-winners')->assertSuccessful();

    Mail::assertSent(CampaignMail::class, 2);
    expect(abState()['phase'])->toBe('winner');
});

it('does not queue the winner pass again while it is still working', function (): void {
    config()->set('marketing.ab.resume_after_minutes', 30);

    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(241)->minutes();
    app(AbTest::class)->decide(app(CampaignRepository::class), app(CampaignRepository::class)->find('juli'));

    // 25 minutes in, the (long, throttled) pass reports in; 25 more is 50 since
    // the claim but only 25 since the last sign of life.
    $this->travel(25)->minutes();
    app(AbTest::class)->heartbeat('juli');
    $this->travel(25)->minutes();

    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 2);

    $this->travel(6)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 4);
});

it('does not finish the campaign while the winner pass is still writing its audience', function (): void {
    $audience = abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(241)->minutes();
    app(AbTest::class)->decide(app(CampaignRepository::class), app(CampaignRepository::class)->find('juli'));

    // A fast worker resolves the first winner message before the later chunks
    // of the pass exist.
    $first = Message::create([
        'campaign_handle' => 'juli',
        'subscription_id' => $audience['r1']->id,
        'email' => 'r1@example.com',
        'status' => Message::STATUS_PENDING,
        'variant' => 'b',
    ]);

    dispatch_sync(new SendMessageJob($first->id));

    Mail::assertSent(CampaignMail::class, 3);
    expect(app(CampaignRepository::class)->find('juli')->status)->toBe(Campaign::STATUS_SENDING);

    // The pass goes on, ends, and only then is the campaign done.
    abStartJob(true);

    Mail::assertSent(CampaignMail::class, 4);
    expect(app(CampaignRepository::class)->find('juli')->status)->toBe(Campaign::STATUS_SENT)
        ->and(abState()['winner_pass_done'])->toBeTrue();
});

it('queues the test pass again when it never completed', function (): void {
    config()->set('marketing.ab.resume_after_minutes', 30);

    abAudience();
    $campaign = abCampaign();
    $campaign->status = Campaign::STATUS_SENDING;
    app(CampaignRepository::class)->save($campaign);

    // The job started and died for good after writing its state.
    app(AbTest::class)->begin(app(CampaignRepository::class), $campaign);

    $this->travel(29)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 0);

    $this->travel(2)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 2);
    expect(abState()['snapshot_done'])->toBeTrue();

    $this->travel(60)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 2);
    expect(Message::forCampaign('juli')->count())->toBe(2);
});

/**
 * A winner job that never ran (lost dispatch, worker gone for good) must not
 * leave the campaign in `sending` for ever.
 */
it('queues the winner pass again when it never completed, and only then', function (): void {
    config()->set('marketing.ab.resume_after_minutes', 30);

    abAudience();
    app(CampaignSender::class)->queue(abCampaign());
    abOpen('tb@example.com');

    $this->travel(241)->minutes();

    // Decided and claimed, but the job was lost before it ran.
    app(AbTest::class)->decide(app(CampaignRepository::class), app(CampaignRepository::class)->find('juli'));
    AbDecision::query()->update(['dispatched_at' => now()]);

    $this->travel(29)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 2);

    $this->travel(2)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 4);
    expect(app(CampaignRepository::class)->find('juli')->status)->toBe(Campaign::STATUS_SENT);

    $this->travel(60)->minutes();
    $this->artisan('marketing:send-ab-winners')->assertSuccessful();
    Mail::assertSent(CampaignMail::class, 4);
    expect(Message::forCampaign('juli')->count())->toBe(4);
});
