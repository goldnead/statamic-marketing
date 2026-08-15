<?php

/**
 * The two dashboard charts: is engagement moving, and is the list growing.
 *
 * The properties, rather than coverage:
 *
 *  - **A fresh install renders.** No campaign, no subscriber, no list. That is
 *    the state every install starts in, it is the one nobody seeds, and a chart
 *    that divides by its own maximum is the shape that fails exactly there.
 *  - **One campaign is not a trend.** The screen has to say so rather than draw
 *    a single bar and let it look like a direction.
 *  - **A quiet week is a nought, not a missing column.** Dropped, the bars
 *    close ranks and a month with one sign-up looks like a busy one — the
 *    chart would be lying in the one direction it is asked about.
 *  - **The rates are CampaignStats' rates.** Not "the same formula", the same
 *    numbers, asserted against the service the campaign page reads.
 */

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Http\Controllers\Cp\DashboardController;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Services\SubscriptionGrowth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\User;

/** The dashboard's Inertia props. */
function dashboardProps(): array
{
    $response = test()->withHeaders(['X-Inertia' => 'true'])->get(cp_route('marketing.dashboard'));

    $response->assertStatus(200);

    return json_decode($response->getContent(), true)['props'] ?? [];
}

beforeEach(function (): void {
    // A Monday, so the twelve week columns are not decided by which day the
    // suite happens to run on.
    CarbonImmutable::setTestNow('2026-08-10 12:00:00');
    Carbon::setTestNow('2026-08-10 12:00:00');

    $user = User::make()->email('dashboard@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    $this->sentCampaign = function (string $handle, string $sentAt, array $people = []): Campaign {
        $campaign = new Campaign(
            handle: $handle,
            name: ucfirst($handle),
            subject: 'Hallo',
            listHandle: 'newsletter',
            status: Campaign::STATUS_SENT,
            sentAt: CarbonImmutable::parse($sentAt),
        );

        app(CampaignRepository::class)->save($campaign);

        foreach ($people as $index => $person) {
            $subscription = Subscription::create([
                'list_handle' => 'newsletter',
                'email' => "{$handle}-{$index}@example.com",
                'status' => Subscription::STATUS_SUBSCRIBED,
                // Out of the growth window on purpose: this helper is about
                // campaigns, and a recipient it creates must not quietly move
                // the list-growth figures another test is asserting.
                'subscribed_at' => CarbonImmutable::parse('2025-01-01 09:00:00'),
            ]);

            Message::create([
                'campaign_handle' => $handle,
                'subscription_id' => $subscription->id,
                'email' => "{$handle}-{$index}@example.com",
                'status' => $person['status'] ?? Message::STATUS_SENT,
                'opens' => $person['opens'] ?? 0,
                'clicks' => $person['clicks'] ?? 0,
                'sent_at' => CarbonImmutable::parse($sentAt),
            ]);
        }

        return $campaign;
    };

    /** A sign-up, optionally with a later sign-off. */
    $this->signUp = function (string $email, ?string $subscribedAt, ?string $unsubscribedAt = null): Subscription {
        $subscription = Subscription::create([
            'list_handle' => 'newsletter',
            'email' => $email,
            'status' => $unsubscribedAt ? Subscription::STATUS_UNSUBSCRIBED : Subscription::STATUS_SUBSCRIBED,
            'subscribed_at' => $subscribedAt,
            'unsubscribed_at' => $unsubscribedAt,
        ]);

        return $subscription;
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('renders a dashboard with no campaign, no list and no subscriber at all', function (): void {
    $props = dashboardProps();

    expect($props['engagement'])->toBe([])
        ->and($props['recentCampaigns'])->toBe([])
        // The weeks are there even when nothing happened in any of them: the
        // screen decides to show a sentence instead of twelve empty tracks,
        // and it can only decide that if it is handed the weeks.
        ->and($props['growth']['weeks'])->toHaveCount(SubscriptionGrowth::WEEKS)
        ->and($props['growth']['totals'])->toBe(['subscribed' => 0, 'unsubscribed' => 0, 'net' => 0])
        ->and(array_sum(array_column($props['growth']['weeks'], 'subscribed')))->toBe(0);
});

it('hands over exactly one campaign when exactly one has been sent', function (): void {
    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
    ($this->sentCampaign)('erste', '2026-08-05 09:00:00', [
        ['opens' => 1, 'clicks' => 1],
        ['opens' => 0],
    ]);

    $props = dashboardProps();

    // The screen says "a trend needs two" — see the Vue test. The server's job
    // is only to be honest that there is one, rather than pad the series.
    expect($props['engagement'])->toHaveCount(1)
        ->and($props['engagement'][0]['handle'])->toBe('erste')
        ->and($props['engagement'][0]['sent'])->toBe(2);
});

it('leaves out a campaign that was never sent', function (): void {
    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
    app(CampaignRepository::class)->save(new Campaign(handle: 'entwurf', name: 'Entwurf', listHandle: 'newsletter'));
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'geplant',
        name: 'Geplant',
        listHandle: 'newsletter',
        status: Campaign::STATUS_SCHEDULED,
        scheduledAt: CarbonImmutable::parse('2026-08-20 09:00:00'),
    ));

    // An open rate of nought on a campaign that has not gone out is not a
    // result, and on a trend line it reads as a collapse.
    expect(dashboardProps()['engagement'])->toBe([]);
});

it('reports the rates CampaignStats reports, not its own arithmetic', function (): void {
    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
    $campaign = ($this->sentCampaign)('brief', '2026-08-05 09:00:00', [
        ['opens' => 2, 'clicks' => 1],
        ['opens' => 1],
        ['opens' => 0],
        ['status' => Message::STATUS_FAILED],
    ]);

    $expected = app(CampaignStats::class)->forCampaign($campaign);
    $row = collect(dashboardProps()['engagement'])->firstWhere('handle', 'brief');

    expect($row['open_rate'])->toBe($expected['open_rate'])
        ->and($row['click_rate'])->toBe($expected['click_rate'])
        ->and($row['sent'])->toBe($expected['sent']);
});

it('puts the campaigns in send order, oldest first, and stops at twelve', function (): void {
    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));

    for ($week = 1; $week <= 15; $week++) {
        ($this->sentCampaign)("brief-{$week}", CarbonImmutable::parse('2026-01-05 09:00:00')->addWeeks($week)->toDateTimeString());
    }

    $handles = array_column(dashboardProps()['engagement'], 'handle');

    expect($handles)->toHaveCount(DashboardController::TREND_CAMPAIGNS)
        // The twelve newest, read left to right in the order they went out.
        ->and($handles[0])->toBe('brief-4')
        ->and($handles[11])->toBe('brief-15');
});

it('keeps a week nobody joined as a nought in the series', function (): void {
    ($this->signUp)('maria@example.com', '2026-08-04 10:00:00');
    // Nothing at all in the week before.
    ($this->signUp)('jonas@example.com', '2026-07-20 10:00:00');

    $weeks = collect(dashboardProps()['growth']['weeks'])
        ->mapWithKeys(fn (array $week) => [CarbonImmutable::parse($week['at'])->toDateString() => $week['subscribed']]);

    expect($weeks->get('2026-08-03'))->toBe(1)
        ->and($weeks->get('2026-07-20'))->toBe(1)
        // The quiet weeks in between are present and nought — not absent.
        ->and($weeks->get('2026-07-27'))->toBe(0)
        ->and($weeks->has('2026-07-27'))->toBeTrue()
        ->and($weeks)->toHaveCount(SubscriptionGrowth::WEEKS);
});

it('counts a sign-off in the week it happened', function (): void {
    ($this->signUp)('weg@example.com', '2026-07-06 10:00:00', '2026-08-05 10:00:00');

    $weeks = collect(dashboardProps()['growth']['weeks'])
        ->mapWithKeys(fn (array $week) => [CarbonImmutable::parse($week['at'])->toDateString() => $week]);

    expect($weeks->get('2026-08-03')['unsubscribed'])->toBe(1)
        ->and($weeks->get('2026-08-03')['subscribed'])->toBe(0)
        ->and($weeks->get('2026-07-06')['subscribed'])->toBe(1)
        ->and($weeks->get('2026-07-06')['net'])->toBe(1)
        ->and($weeks->get('2026-08-03')['net'])->toBe(-1);
});

it('falls back to the row date where no sign-up date was ever written', function (): void {
    // What an import or a row older than the column looks like. Without the
    // fallback those people never joined in any week, and a list that grew by
    // five hundred addresses overnight would show a flat line.
    $subscription = ($this->signUp)('import@example.com', null);
    DB::table('marketing_subscriptions')
        ->where('id', $subscription->id)
        ->update(['created_at' => '2026-08-04 09:00:00', 'subscribed_at' => null]);

    $weeks = collect(dashboardProps()['growth']['weeks'])
        ->mapWithKeys(fn (array $week) => [CarbonImmutable::parse($week['at'])->toDateString() => $week['subscribed']]);

    expect($weeks->get('2026-08-03'))->toBe(1);
});

it('leaves out what happened before the window', function (): void {
    ($this->signUp)('alt@example.com', '2026-01-05 10:00:00');
    ($this->signUp)('neu@example.com', '2026-08-04 10:00:00');

    expect(dashboardProps()['growth']['totals']['subscribed'])->toBe(1);
});
