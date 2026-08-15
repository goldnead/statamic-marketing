<?php

/**
 * The activity curve: when a campaign was read, and by whom.
 *
 * The property this file exists for is the third one down. Apple's Mail
 * Privacy Protection fetches the tracking pixel when it DELIVERS a message,
 * so a curve drawn from raw opens is a wall in the first hour and a flat line
 * after it — a picture that says "everybody read it immediately" about a
 * campaign nobody may have read at all. The machine share therefore has to
 * survive all the way to the screen as its own number. Summed into the opens
 * anywhere on the way, the chart becomes confidently wrong, and nothing else
 * in the suite would notice.
 *
 * The rest are the ways a chart breaks quietly: a campaign with no events at
 * all, one campaign's events showing up under another, and gaps in the middle
 * of the axis that let the bars close ranks around a quiet stretch.
 */

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignReport;
use Illuminate\Support\Carbon;

/** The sum of one series across the whole axis. */
function activityTotal(array $activity, string $series): int
{
    return array_sum(array_column($activity['buckets'], $series));
}

beforeEach(function (): void {
    // A fixed clock: an adaptive grid whose threshold is three days cannot be
    // tested against a moving "now" without the odd run landing on the far
    // side of the boundary.
    CarbonImmutable::setTestNow('2026-08-14 12:00:00');
    Carbon::setTestNow('2026-08-14 12:00:00');

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));

    $this->send = function (string $handle, string $sentAt): Campaign {
        $campaign = new Campaign(
            handle: $handle,
            name: ucfirst($handle),
            subject: 'Hallo',
            listHandle: 'newsletter',
            status: Campaign::STATUS_SENT,
            sentAt: CarbonImmutable::parse($sentAt),
        );

        app(CampaignRepository::class)->save($campaign);

        return $campaign;
    };

    /** One delivery, and the events that happened to it. */
    $this->delivery = function (string $handle, string $email, array $events = []): Message {
        $subscription = Subscription::create([
            'list_handle' => 'newsletter',
            'email' => $email,
            'status' => Subscription::STATUS_SUBSCRIBED,
        ]);

        $message = Message::create([
            'campaign_handle' => $handle,
            'subscription_id' => $subscription->id,
            'email' => $email,
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        foreach ($events as [$type, $machine, $at]) {
            MessageEvent::create([
                'message_id' => $message->id,
                'type' => $type,
                'machine' => $machine,
                'created_at' => CarbonImmutable::parse($at),
            ]);
        }

        return $message;
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
    // One test below moves the process onto a timezone that has a DST fold in
    // it. PHP's default timezone is process-wide and outlives the test.
    date_default_timezone_set('UTC');
});

it('renders a campaign nobody has opened without inventing an axis', function (): void {
    $campaign = ($this->send)('still', '2026-08-14 09:00:00');
    ($this->delivery)('still', 'niemand@example.com');

    $activity = app(CampaignReport::class)->activity($campaign);

    expect($activity['buckets'])->toBe([])
        ->and($activity['totals'])->toBe(['human_opens' => 0, 'machine_opens' => 0, 'clicks' => 0])
        ->and($activity['beyond'])->toBe(0)
        // No division has happened, so no NaN can have been produced. Asserted
        // on the shape rather than on a rendered string, because the screen
        // reads these keys and a missing one is what would print "undefined".
        ->and($activity)->toHaveKeys(['unit', 'buckets', 'totals', 'beyond']);
});

it('keeps the machine share out of the human opens', function (): void {
    $campaign = ($this->send)('brief', '2026-08-14 09:00:00');

    ($this->delivery)('brief', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, true, '2026-08-14 09:01:00'],   // Apple, on delivery
        [MessageEvent::TYPE_OPEN, false, '2026-08-14 11:20:00'],  // Maria, two hours later
        [MessageEvent::TYPE_CLICK, false, '2026-08-14 11:22:00'],
    ]);
    ($this->delivery)('brief', 'jonas@example.com', [
        [MessageEvent::TYPE_OPEN, true, '2026-08-14 09:01:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    // The first hour is two machine opens and no person at all — which is the
    // whole point. A chart that added them up would show a two-open spike here
    // and read as "two people opened it straight away".
    $first = collect($activity['buckets'])->firstWhere('at', CarbonImmutable::parse('2026-08-14 09:00:00')->toIso8601String());

    expect($first['machine_opens'])->toBe(2)
        ->and($first['human_opens'])->toBe(0)
        ->and($activity['totals'])->toBe(['human_opens' => 1, 'machine_opens' => 2, 'clicks' => 1])
        // And nowhere on the axis is a bucket whose human figure has quietly
        // absorbed a machine.
        ->and(activityTotal($activity, 'human_opens'))->toBe(1)
        ->and(activityTotal($activity, 'machine_opens'))->toBe(2);
});

it('counts only its own campaign', function (): void {
    $campaign = ($this->send)('brief', '2026-08-14 09:00:00');
    ($this->send)('anderer', '2026-08-14 09:00:00');

    ($this->delivery)('brief', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-08-14 10:00:00'],
    ]);
    ($this->delivery)('anderer', 'fremd@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-08-14 10:00:00'],
        [MessageEvent::TYPE_CLICK, false, '2026-08-14 10:05:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    expect($activity['totals'])->toBe(['human_opens' => 1, 'machine_opens' => 0, 'clicks' => 0]);
});

it('leaves out the unsubscribes and bounces — this chart is about reading', function (): void {
    $campaign = ($this->send)('brief', '2026-08-14 09:00:00');

    ($this->delivery)('brief', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-08-14 10:00:00'],
        [MessageEvent::TYPE_UNSUBSCRIBE, false, '2026-08-14 10:30:00'],
        [MessageEvent::TYPE_BOUNCE, false, '2026-08-14 10:40:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    expect($activity['totals'])->toBe(['human_opens' => 1, 'machine_opens' => 0, 'clicks' => 0]);
});

it('draws hours while a campaign is still fresh', function (): void {
    $campaign = ($this->send)('brief', '2026-08-14 09:00:00');

    ($this->delivery)('brief', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-08-14 09:30:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-08-14 12:30:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    // 09:00 through 12:00 — four hourly buckets, the two in between empty.
    expect($activity['unit'])->toBe('hour')
        ->and($activity['buckets'])->toHaveCount(4)
        ->and(array_column($activity['buckets'], 'human_opens'))->toBe([1, 0, 0, 1]);
});

it('switches to days once a campaign has been read for longer than that', function (): void {
    $campaign = ($this->send)('brief', '2026-08-01 09:00:00');

    // Read across nine days, not read once and then touched once: the reading
    // has to be where the events are, because that is what the unit is now
    // chosen from. Two events nine days apart is the other case entirely — one
    // straggler — and the test below is the one that owns it.
    ($this->delivery)('brief', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-08-01 09:30:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-08-03 10:00:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-08-05 10:00:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-08-07 10:00:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-08-09 10:00:00'],
        // Nine days on: hourly would be over two hundred bars.
        [MessageEvent::TYPE_OPEN, false, '2026-08-10 18:00:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    expect($activity['unit'])->toBe('day')
        ->and($activity['buckets'])->toHaveCount(10)
        ->and(array_column($activity['buckets'], 'human_opens'))->toBe([1, 0, 1, 0, 1, 0, 1, 0, 1, 1]);
});

it('starts the axis at the send, not at the first open', function (): void {
    $campaign = ($this->send)('brief', '2026-08-14 09:00:00');

    ($this->delivery)('brief', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-08-14 11:00:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    // Two hours of silence after the send is a fact about the campaign, and an
    // axis that began at the first open would hide it.
    expect($activity['buckets'][0]['at'])->toBe(CarbonImmutable::parse('2026-08-14 09:00:00')->toIso8601String())
        ->and($activity['buckets'][0]['human_opens'])->toBe(0)
        ->and($activity['buckets'])->toHaveCount(3);
});

it('does not let one straggler take the hourly grid away from the reading', function (): void {
    // The property the class docblock promises: "one late bounce notice cannot
    // turn the chart into…". Chosen from the last event, this campaign is read
    // over half a year and drawn on a daily grid, and the four hours anybody
    // actually read it are four bars out of ninety. The reading is where the
    // events are, and that is what the unit is taken from.
    $campaign = ($this->send)('brief', '2026-01-01 09:00:00');

    ($this->delivery)('brief', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-01-01 09:30:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-01-01 10:15:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-01-01 11:40:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-01-01 12:05:00'],
        // Somebody finds the mail again half a year on. Ordinary, and n = 1.
        [MessageEvent::TYPE_CLICK, false, '2026-07-20 08:00:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    expect($activity['unit'])->toBe('hour')
        // The four hours of reading are four hours, one bar each.
        ->and(array_slice(array_column($activity['buckets'], 'human_opens'), 0, 4))->toBe([1, 1, 1, 1])
        // The axis is still capped, so the half year does not become a wall of
        // empty bars…
        ->and($activity['buckets'])->toHaveCount(CampaignReport::ACTIVITY_MAX_BUCKETS)
        // …and what falls off it is counted rather than hidden.
        ->and($activity['beyond'])->toBe(1)
        ->and($activity['totals']['clicks'])->toBe(1);
});

it('stays quiet when the chart plainly shows a split, however old the campaign', function (): void {
    // A campaign sent shortly before the column arrived keeps collecting opens
    // after it, and those carry the flag. The sentence would then sit directly
    // under an orange bar and say the split cannot be read — a warning the
    // reader can see is untrue, which is worse than no warning at all.
    //
    // The send date decides whether the warning is *possible*; whether a
    // single preload was ever recorded decides whether it is *true*.
    $before = CarbonImmutable::parse(CampaignReport::MACHINE_DETECTION_SINCE)->subDay();
    $campaign = ($this->send)('grenzfall', $before->format('Y-m-d H:i:s'));

    ($this->delivery)('grenzfall', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, $before->addMinutes(5)->format('Y-m-d H:i:s')],
        // The day after, with the column in place.
        [MessageEvent::TYPE_OPEN, true, $before->addDay()->addHours(2)->format('Y-m-d H:i:s')],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    expect($activity['predates_detection'])->toBeFalse()
        ->and($activity['totals']['machine_opens'])->toBe(1);
});

it('says when a campaign is older than the ability to tell a machine open apart', function (): void {
    // Every open recorded before the migration carries the column default,
    // which is "a person" — so the delivery wall of every campaign in the
    // archive is drawn in green, as readers. The figure is what the tiles
    // always said and stays; the chart is the one place where it misleads, so
    // the chart gets a sentence.
    $before = CarbonImmutable::parse(CampaignReport::MACHINE_DETECTION_SINCE)->subDay();
    $campaign = ($this->send)('alt', $before->format('Y-m-d H:i:s'));

    ($this->delivery)('alt', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, $before->addMinutes(5)->format('Y-m-d H:i:s')],
    ]);

    $newer = ($this->send)('neu', '2026-08-16 09:00:00');
    ($this->delivery)('neu', 'jonas@example.com', [
        [MessageEvent::TYPE_OPEN, true, '2026-08-16 09:05:00'],
    ]);

    $report = app(CampaignReport::class);

    expect($report->activity($campaign)['predates_detection'])->toBeTrue()
        ->and($report->activity($campaign)['detection_since'])->toBe(CampaignReport::MACHINE_DETECTION_SINCE)
        ->and($report->activity($newer)['predates_detection'])->toBeFalse();
});

it('loses nothing to the hour the clocks go back', function (): void {
    // Buckets are a `substr` of the stored timestamp in the app's own timezone
    // (see TimeBucket), so on the night the clocks go back both real 02:00
    // hours truncate to the same bucket. That is benign — they are summed once
    // and emitted once — but it is benign by accident of two mechanisms lining
    // up, and the way it would stop being benign is silent: an hour of opens
    // dropped, or the same hour counted into two bars.
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    $campaign = ($this->send)('nacht', '2026-10-25 00:00:00');

    ($this->delivery)('nacht', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-10-25 01:30:00'],
        [MessageEvent::TYPE_OPEN, false, '2026-10-25 02:15:00'],   // CEST
        [MessageEvent::TYPE_OPEN, false, '2026-10-25 02:45:00'],   // CET, same wall clock
        [MessageEvent::TYPE_CLICK, false, '2026-10-25 03:15:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    expect($activity['unit'])->toBe('hour')
        ->and($activity['beyond'])->toBe(0)
        // Nothing dropped and nothing doubled: the axis carries exactly the
        // totals, whatever the clock did in the middle of it.
        ->and(activityTotal($activity, 'human_opens'))->toBe(3)
        ->and(activityTotal($activity, 'clicks'))->toBe(1)
        ->and($activity['totals'])->toBe(['human_opens' => 3, 'machine_opens' => 0, 'clicks' => 1]);
});

it('still draws a campaign whose send date was never recorded', function (): void {
    $campaign = new Campaign(handle: 'ohne', name: 'Ohne', subject: 'Hallo', listHandle: 'newsletter');
    app(CampaignRepository::class)->save($campaign);

    ($this->delivery)('ohne', 'maria@example.com', [
        [MessageEvent::TYPE_OPEN, false, '2026-08-14 10:00:00'],
    ]);

    $activity = app(CampaignReport::class)->activity($campaign);

    expect($activity['buckets'])->toHaveCount(1)
        ->and($activity['buckets'][0]['human_opens'])->toBe(1);
});
