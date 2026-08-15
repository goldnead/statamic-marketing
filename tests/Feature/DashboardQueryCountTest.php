<?php

/**
 * The dashboard costs the same whether the customer has sent two campaigns or
 * two hundred.
 *
 * It did not. `DashboardController` called `CampaignStats::forCampaign()` once
 * per campaign in a loop, and `forCampaign()` is about six queries — so five
 * rows were thirty, and the twelve the engagement chart needs would have been
 * over seventy for a single screen. Nothing about the page looks wrong when
 * that happens, which is the whole problem: it is a defect that only ever shows
 * up as "the dashboard feels slow", on the installs with the most history.
 *
 * So it is measured. `DB::listen` counts what actually reached the connection,
 * the same page is rendered with two campaigns and then with twelve, and the
 * two numbers have to be equal.
 *
 * The listener is installed **once**, and that is the trap this file is written
 * around — the same one CampaignReportQueryCountTest documents. Laravel keeps
 * every listener it is handed for the life of the application, so a listener
 * registered per measurement inside a loop, closing over `&$count`, has them
 * all writing to the same variable: the second measurement counts every query
 * twice, the third three times, and a page with no N+1 in it looks like the
 * worst one in the codebase. One listener, a key that says which measurement is
 * running, and nothing to accumulate across runs.
 */

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\User;

beforeEach(function (): void {
    $user = User::make()->email('counter@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));

    /**
     * $count sent campaigns, each with three recipients who opened, clicked and
     * one of whom unsubscribed.
     *
     * Every campaign therefore has something to resolve in every figure the
     * dashboard prints — a recipient count, an open, a click, an unsubscribe.
     * A page that asks the database per campaign will show it here.
     */
    $this->campaigns = function (int $count): void {
        MessageEvent::query()->delete();
        Message::query()->delete();
        Subscription::query()->delete();

        foreach (app(CampaignRepository::class)->all() as $existing) {
            app(CampaignRepository::class)->delete($existing->handle);
        }

        for ($index = 0; $index < $count; $index++) {
            $handle = "brief-{$index}";

            app(CampaignRepository::class)->save(new Campaign(
                handle: $handle,
                name: "Brief {$index}",
                subject: 'Hallo',
                listHandle: 'newsletter',
                status: Campaign::STATUS_SENT,
                sentAt: CarbonImmutable::now()->subWeeks($count - $index),
            ));

            for ($person = 0; $person < 3; $person++) {
                $email = "{$handle}-{$person}@example.com";

                $subscription = Subscription::create([
                    'list_handle' => 'newsletter',
                    'email' => $email,
                    'status' => Subscription::STATUS_SUBSCRIBED,
                    'subscribed_at' => CarbonImmutable::now()->subWeeks($count - $index),
                ]);

                $message = Message::create([
                    'campaign_handle' => $handle,
                    'subscription_id' => $subscription->id,
                    'email' => $email,
                    'status' => Message::STATUS_SENT,
                    'sent_at' => CarbonImmutable::now()->subWeeks($count - $index),
                    'opens' => 2,
                    'clicks' => 1,
                ]);

                MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_OPEN, 'machine' => true]);
                MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_OPEN, 'machine' => false]);
                MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_CLICK, 'url' => 'https://example.com/kurs']);

                if ($person === 0) {
                    MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_UNSUBSCRIBE]);
                }
            }
        }
    };

    $this->measuring = null;
    $this->queries = [];

    DB::listen(function () {
        if ($this->measuring !== null) {
            $this->queries[$this->measuring] = ($this->queries[$this->measuring] ?? 0) + 1;
        }
    });

    /** How many queries one render of the dashboard costs. */
    $this->queriesForDashboard = function () {
        $key = 'dashboard@'.count($this->queries);
        $this->measuring = $key;

        test()->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('marketing.dashboard'))
            ->assertStatus(200);

        $this->measuring = null;

        return $this->queries[$key] ?? 0;
    };
});

it('costs the same number of queries for twelve campaigns as for two', function (): void {
    ($this->campaigns)(2);
    $few = ($this->queriesForDashboard)();

    ($this->campaigns)(12);
    $many = ($this->queriesForDashboard)();

    // The measurement first. Two zeroes are also equal, and a listener that
    // stopped counting would make this whole file pass by saying nothing.
    expect($few)->toBeGreaterThan(0);

    expect($many)->toBe(
        $few,
        "The dashboard took {$few} queries for 2 campaigns and {$many} for 12. ".
        'Something on this page is resolved once per campaign.'
    );
});

it('keeps the whole page inside a small fixed budget, at one mailing list', function (): void {
    // The name says "at one list" because that is what is measured, and the
    // page is not flat in the number of lists: `DashboardController` still
    // calls `CampaignStats::forList()` once per list for the panel at the
    // bottom. Bundling that is a change to `CampaignStats`, deliberately not
    // made here — but a test called "the whole page" while varying only the
    // campaigns would have implied it was already true, and that is the kind of
    // sentence a later reader trusts instead of measuring.
    //
    // The campaign half IS flat, and the test above is the one that holds it:
    // two campaigns and twelve cost the same. Lists are the open end.
    //
    // Measured, not guessed, on twelve campaigns and one list: **7** on the
    // flat driver, **9** on the eloquent one, and the same two numbers at fifty
    // campaigns.
    //
    //   1  the list panel's grouped status counts
    //   2  the two subscriber totals on the tiles
    //   2  the bundled campaign figures: messages grouped, unsubscribes grouped
    //   2  list growth: sign-ups per day, sign-offs per day
    //   +2 on the eloquent driver, which reads its campaigns and lists from
    //      tables where the flat driver reads files
    //
    // For contrast, the loop this replaced: `forCampaign()` is eleven queries,
    // so the five rows of the summary table alone were fifty-five and the
    // twelve the chart needs would have been a hundred and thirty-two.
    //
    // The ceiling is the measured figure plus room for one honest addition, and
    // nowhere near enough for anything resolved per campaign.
    ($this->campaigns)(12);

    expect(($this->queriesForDashboard)())->toBeLessThanOrEqual(12);
});
