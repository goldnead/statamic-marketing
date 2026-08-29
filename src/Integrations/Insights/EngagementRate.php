<?php

namespace Goldnead\Marketing\Integrations\Insights;

use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * What the two rates share: the same denominator, and the same idea of a person.
 *
 * **Delivered mails are the denominator**, unchanged from `CampaignStats::shape()`
 * — which is the one place in this addon a rate is defined, and the reason it
 * is one place. A rate built on recipients instead would drop the moment
 * anything failed, was skipped or was capped, and would no longer be comparable
 * with every campaign measured before it.
 *
 * **A person is a message, counted once.** Somebody who opens the same mail six
 * times is one reader, so the numerator counts messages that have such an event
 * and not the events. That, too, is what `CampaignStats` does.
 *
 * **The cohort is the mails sent in the window, and every open of them counts —
 * including the ones that arrive later.** The alternative is to count the
 * events dated inside the window, and it is worse: an open in August of a mail
 * sent in March would be divided by August's sends, so the numerator and the
 * denominator would describe different mails. The price of the choice, stated
 * plainly because a reader will notice it: a period that has just closed still
 * moves for a day or two while people get to their mail. That is the truth
 * about email, and it is why an open rate is never final on the evening of the
 * send.
 *
 * **Null, never nought.** A window in which nothing was sent has no rate. "0 %
 * opened" is a statement about mails that were never sent, and on this screen it
 * would sit directly beside a send count of zero that contradicts it.
 */
abstract class EngagementRate extends MarketingMetric
{
    protected function table(): string
    {
        return 'marketing_messages';
    }

    protected function timestamp(): string
    {
        return 'sent_at';
    }

    public function unit(): string
    {
        return Unit::PERCENT;
    }

    /**
     * Both tables, not just the one the window is cut on.
     *
     * The numerator lives in the events table. Without it the rate is not a
     * zero, it is a question nothing can answer — and on a half-migrated
     * install the query would be an exception rather than a figure.
     */
    public function available(): bool
    {
        return parent::available() && Schema::hasTable('marketing_message_events');
    }

    /** The mails whose reading is being counted: those that went out in the window. */
    protected function sentInPeriod(MetricQuery $query): Builder
    {
        return $this->untilNow($query);
    }

    /** Those of them that a person did the thing to. Defined by the subclass. */
    abstract protected function engagedInPeriod(MetricQuery $query): Builder;

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $sent = (int) $this->sentInPeriod($query)->count();

        if ($sent <= 0) {
            return null;
        }

        // One decimal, as everywhere in this addon. A rate is read to compare
        // sends, and "23.4629 %" asserts a precision that four hundred
        // recipients cannot carry.
        return round((int) $this->engagedInPeriod($query)->count() / $sent * 100, 1);
    }

    /**
     * A rate per bucket, and only where there is something to divide by.
     *
     * A day on which nothing went out is left out rather than shown as zero —
     * the same rule as the headline, applied per column. Insights would fill
     * such a bucket with a zero; that is right for a count and wrong for a
     * rate, so the metric hands back `null` for it instead, which the contract
     * carries all the way to a chart that draws no bar rather than a bar of
     * nothing.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $sent = $this->bucketed($this->sentInPeriod($query), $query, 'count(*)');
        $engaged = $this->bucketed($this->engagedInPeriod($query), $query, 'count(*)');

        $buckets = [];

        foreach ($sent as $bucket => $count) {
            $buckets[$bucket] = (int) $count > 0
                ? round((int) ($engaged[$bucket] ?? 0) / (int) $count * 100, 1)
                : null;
        }

        ksort($buckets);

        return $buckets;
    }

    /**
     * Messages with an event of one of these kinds against them.
     *
     * A sub-select on the events rather than a join, which is the established
     * pattern here (`CampaignStats::metrics()`) and the thing that keeps a
     * message counted once however many events it collected.
     *
     * No brand condition on the inner query, and it is not missing: the events
     * are tied to a message by id, and the outer query has already narrowed the
     * messages to one brand. The denormalised `brand_id` on the events table is
     * a defence for queries that start there; this one does not.
     *
     * @param  callable(Builder): Builder  $narrow  what makes an event count
     */
    protected function withEvent(MetricQuery $query, callable $narrow): Builder
    {
        return $this->sentInPeriod($query)->whereExists(
            fn (Builder $events) => $narrow(
                $events->from('marketing_message_events')
                    ->whereColumn('marketing_message_events.message_id', 'marketing_messages.id')
            )
        );
    }

    /**
     * A click is a person, and it has to be.
     *
     * Under Apple's Mail Privacy Protection the proxy fetches the tracking pixel
     * for every delivered message, so the only open on record can be the
     * machine's — and a click writes no open event and does not touch the
     * `opens` counter (see `TrackingService::recordClick`). Counting opens alone
     * would report "read by nobody" for a send that people clicked through.
     *
     * Carried over from `CampaignReport::humanOpens()` word for word, because a
     * rewritten definition is a different number and a rate that changed during
     * a refactor is indistinguishable from one that broke.
     */
    protected function humanOpen(Builder $events): Builder
    {
        return $events->where(function (Builder $either): void {
            $either
                ->where(function (Builder $open): void {
                    $open->where('type', MessageEvent::TYPE_OPEN)->where('machine', false);
                })
                ->orWhere('type', MessageEvent::TYPE_CLICK);
        });
    }
}
