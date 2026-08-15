<?php

namespace Goldnead\Marketing\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\TimeBucket;

/**
 * "Is the list growing?" — sign-ups against sign-offs, week by week.
 *
 * Which timestamps this reads, and why, because the columns do not all mean
 * what their names suggest:
 *
 *  - **`subscribed_at`** is written by `SubscriptionService::subscribe()`, the
 *    one path a public sign-up can take, and it is written at the moment the
 *    form is submitted — before a double opt-in is confirmed. So a week here
 *    counts sign-UPS, not confirmed subscribers. That is the honest reading of
 *    "did people join this week"; counting `confirmed_at` instead would look
 *    stricter and be worse, because it is NULL on every row that arrived by
 *    import or predates the column, and those weeks would silently read zero.
 *    `ConsentIntegrityCommand` exists partly because such rows are real.
 *  - **`created_at`** stands in where `subscribed_at` is NULL, which is what a
 *    row written by anything other than the sign-up path looks like. Same
 *    fallback, same order, as the 2026_08_13 migration uses.
 *  - **`unsubscribed_at`** is written by `SubscriptionService::unsubscribe()`
 *    on every route out — link, preference centre, bounce handling — so it is
 *    the complete one. It is also **cleared** when the same address subscribes
 *    again, and that is the caveat worth knowing: somebody who left in week two
 *    and came back in week five does not appear in week two at all.
 *
 * Both columns are therefore statements about the subscription as it stands
 * today, not an immutable ledger, and this chart is a picture of the list as it
 * is now. The alternative for the leaving half — unsubscribe EVENTS, which are
 * never rewritten — is worse for this question: `SubscriptionService` only
 * records one where the unsubscribe carried a message, so every sign-off from
 * the preference centre would be missing. Complete beats immutable here, and
 * the two halves at least agree with each other: both describe the current row.
 *
 * Two queries, whatever the window. Grouped by day in the database and folded
 * into weeks here, because a week is not a thing SQL agrees about across
 * engines and a day is.
 */
class SubscriptionGrowth
{
    /** Three months. Long enough to show a direction, short enough to read. */
    public const WEEKS = 12;

    /**
     * @return array{weeks:list<array{at:string, subscribed:int, unsubscribed:int, net:int}>,
     *               totals:array{subscribed:int, unsubscribed:int, net:int}}
     */
    public function weekly(int $weeks = self::WEEKS): array
    {
        $weeks = max(1, $weeks);

        // Monday named outright rather than left to the locale: the same
        // install would otherwise regroup its own history the day somebody
        // changes the CP language.
        $currentWeek = CarbonImmutable::now()->startOfWeek(CarbonInterface::MONDAY);
        $firstWeek = $currentWeek->subWeeks($weeks - 1);

        $joined = $this->countByDay('COALESCE(subscribed_at, created_at)', $firstWeek);
        $left = $this->countByDay('unsubscribed_at', $firstWeek);

        $rows = [];
        $totals = ['subscribed' => 0, 'unsubscribed' => 0, 'net' => 0];

        for ($index = 0; $index < $weeks; $index++) {
            $week = $firstWeek->addWeeks($index);
            $key = $week->format('Y-m-d');

            // A week nobody joined is a nought, not a missing column. Dropped,
            // the bars close ranks and a quiet month looks like a busy one —
            // which is the exact opposite of what this chart is asked.
            $subscribed = $joined[$key] ?? 0;
            $unsubscribed = $left[$key] ?? 0;

            $rows[] = [
                'at' => $week->toIso8601String(),
                'subscribed' => $subscribed,
                'unsubscribed' => $unsubscribed,
                'net' => $subscribed - $unsubscribed,
            ];

            $totals['subscribed'] += $subscribed;
            $totals['unsubscribed'] += $unsubscribed;
        }

        $totals['net'] = $totals['subscribed'] - $totals['unsubscribed'];

        return ['weeks' => $rows, 'totals' => $totals];
    }

    /**
     * Rows per day for one timestamp expression, already folded onto the Monday
     * of the week each day belongs to.
     *
     * @return array<string, int> `Y-m-d` of a Monday => count
     */
    protected function countByDay(string $expression, CarbonImmutable $from): array
    {
        $day = TimeBucket::of($expression, TimeBucket::DAY);

        $rows = Subscription::query()
            ->whereRaw("{$expression} >= ?", [$from->format('Y-m-d H:i:s')])
            ->toBase()
            ->selectRaw("{$day} as day, COUNT(*) as aggregate")
            ->groupByRaw($day)
            ->get();

        $weeks = [];

        foreach ($rows as $row) {
            if ($row->day === null || $row->day === '') {
                continue;
            }

            $key = CarbonImmutable::parse((string) $row->day)
                ->startOfWeek(CarbonInterface::MONDAY)
                ->format('Y-m-d');

            $weeks[$key] = ($weeks[$key] ?? 0) + (int) $row->aggregate;
        }

        return $weeks;
    }
}
