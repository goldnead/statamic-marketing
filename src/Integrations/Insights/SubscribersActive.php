<?php

namespace Goldnead\Marketing\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How large the list was at the end of the period.
 *
 * A stock, not a flow, and the only one this addon contributes. Every other
 * figure here counts things that happened inside the window; this one counts
 * what stood at the end of it. "Forty joined in August" and "there are two
 * thousand three hundred" are both true and neither implies the other.
 *
 * **Consent held at that moment**, expressed with the two columns that carry a
 * date: confirmed by then, and not withdrawn by then.
 *
 * **`status` is deliberately not in the query**, which is the decision worth
 * defending. It is today's fact about the row, and using it would apply today
 * to a date in the past: a subscriber who left in September would vanish from
 * August's figure the moment they left, and every past month would move a
 * little every day. The pair of timestamps is what says whether consent stood
 * at that instant, and it is the only thing that does.
 *
 * Two consequences of that, both intended:
 *
 * - **A bounced or complained address still counts.** `EspEventProcessor`
 *   changes the status and writes no date, so those endings cannot be placed in
 *   time at all. And they are endings of *deliverability*, not of consent —
 *   which is the suppression addon's question, and it has its own screens for
 *   it. A list of two thousand of which fifty cannot be reached is two thousand
 *   subscribers and a delivery problem.
 * - **A row whose confirmation predates the column, or arrived by import, is
 *   not counted at all.** It has no `confirmed_at`, so there is no moment at
 *   which it can be said to have joined. `php artisan marketing:consent-integrity`
 *   is what finds those rows; guessing a date for them here would put a number
 *   on a screen that no consent record supports.
 *
 * **The series returns every bucket, including the unchanged ones.** Insights
 * fills a missing bucket with a zero, which is right for a flow and
 * catastrophic for a stock: a quiet week would draw the list collapsing to
 * nobody and recovering.
 */
class SubscribersActive extends MarketingMetric
{
    protected function table(): string
    {
        return 'marketing_subscriptions';
    }

    /**
     * The timestamp a bucket is cut on: when consent was given.
     *
     * Only half of what this metric reads — the leaving half is
     * `unsubscribed_at` and is asked for separately below. The base class needs
     * one column to window on, and this is the one that decides who exists at
     * all.
     */
    protected function timestamp(): string
    {
        return 'confirmed_at';
    }

    public function handle(): string
    {
        return 'marketing.subscribers_active';
    }

    public function label(): string
    {
        return __('marketing::insights.subscribers_active');
    }

    public function description(): ?string
    {
        return __('marketing::insights.subscribers_active_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return $this->stockAt($query->period->to);
    }

    /**
     * The stock at the end of every bucket, in three queries.
     *
     * Not one count per bucket: a year of daily buckets would be three hundred
     * and sixty-five counts over the whole table. The stock before the window
     * is counted once, and the movements inside it are added on — arrivals
     * minus departures, bucket by bucket, which is the same number by a cheaper
     * road.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $buckets = $this->bucketsIn($query);

        if ($buckets === []) {
            return [];
        }

        $joined = $this->bucketed($this->untilNow($query, 'confirmed_at'), $query, 'count(*)', 'confirmed_at');

        // Only rows that had a confirmation to lose. A sign-up that never
        // confirmed and then unsubscribed was never in the stock, and counting
        // its departure would take somebody else out of it.
        $left = $this->bucketed(
            $this->untilNow($query, 'unsubscribed_at')->whereNotNull('confirmed_at'),
            $query,
            'count(*)',
            'unsubscribed_at',
        );

        // One instant before the first bucket opens, so somebody who confirmed
        // inside that first bucket is counted by the movement and not by the
        // baseline as well.
        $stock = $this->stockAt(reset($buckets)['from']->copy()->subSecond());

        $series = [];

        foreach ($buckets as $key => $bucket) {
            $stock += (int) ($joined[$key] ?? 0) - (int) ($left[$key] ?? 0);
            $series[$key] = $stock;
        }

        return $series;
    }

    /**
     * How many subscriptions held consent at that instant.
     *
     * Its own `where` clauses rather than the base class's window, and that is
     * not a shortcut. `inPeriod()` refuses a row whose timestamp is null,
     * because a row that cannot be placed in time is in no period — right for
     * every flow figure and exactly wrong here, where `unsubscribed_at IS NULL`
     * is precisely the condition for still being subscribed.
     *
     * The end of an open-ended period is **this moment**, not the end of time:
     * a stock is asked as of an instant, and the table can hold dates ahead of
     * it.
     */
    protected function stockAt(?Carbon $at): int
    {
        $at ??= Carbon::now();

        return (int) $this->brandScoped(DB::table($this->table()))
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '<=', $at)
            ->where(fn ($still) => $still->whereNull('unsubscribed_at')->orWhere('unsubscribed_at', '>', $at))
            ->count();
    }

    /**
     * Every bucket the window covers, in order, with the instant each opens.
     *
     * Built from the period rather than from the data, because the whole point
     * of a stock series is the buckets in which nothing happened.
     *
     * An open-ended period has no first bucket to start from, so the earliest
     * confirmation provides one. With no confirmed subscription at all there is
     * nothing to draw and the series is empty — which is the honest shape, not
     * a flat line at zero reaching back to the epoch.
     *
     * @return array<string, array{from: Carbon, to: Carbon}>
     */
    protected function bucketsIn(MetricQuery $query): array
    {
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;

        $from = $query->period->from ?? $this->earliestConfirmation();

        if ($from === null) {
            return [];
        }

        $to = $query->period->to ?? Carbon::now();
        $from = $from->copy();
        $cursor = $monthly ? $from->copy()->startOfMonth() : $from->copy()->startOfDay();

        $buckets = [];

        // A guard rather than a promise about the data: a period is at most a
        // year of days or a decade of months on any screen Insights draws, and
        // an unbounded loop over a bad period would take the request with it.
        $limit = 4000;

        while ($cursor <= $to && count($buckets) < $limit) {
            $end = $monthly ? $cursor->copy()->endOfMonth() : $cursor->copy()->endOfDay();

            $buckets[$cursor->format($monthly ? 'Y-m' : 'Y-m-d')] = [
                // The first bucket starts where the period does, not where its
                // calendar month does — otherwise the baseline is taken from
                // before the month and the first column counts arrivals the
                // period never asked about.
                'from' => $cursor < $from ? $from->copy() : $cursor->copy(),
                'to' => $end,
            ];

            $cursor = $monthly ? $cursor->copy()->addMonth()->startOfMonth() : $cursor->copy()->addDay();
        }

        return $buckets;
    }

    protected function earliestConfirmation(): ?Carbon
    {
        $earliest = $this->brandScoped(DB::table($this->table()))->min('confirmed_at');

        return $earliest === null ? null : Carbon::parse((string) $earliest);
    }
}
