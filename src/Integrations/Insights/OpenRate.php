<?php

namespace Goldnead\Marketing\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * How many of the mails sent were read by a person.
 *
 * **People, not machines.** Apple's Mail Privacy Protection fetches the
 * tracking pixel for every message it delivers, shortly after delivery, whether
 * anybody looks or not — and then caches it, so the reading that follows leaves
 * no trace at all. An open rate that counted those would say a mail nobody
 * opened was opened, and would stay silent about the one they did. The `machine`
 * column on the event carries the verdict, and this figure is built on it.
 *
 * That makes this number **lower than the `open_rate` in `CampaignStats`**, on
 * purpose and by design. That one is what every campaign in the archive was
 * measured with and is deliberately left alone; this one is the honest reading
 * of the same events and is what belongs beside a revenue figure. The two are
 * not the same question and are not named as though they were.
 *
 * One caveat inherited from the column: machine detection began on 2026-08-15
 * (`CampaignReport::MACHINE_DETECTION_SINCE`), and every event written before
 * it carries `machine = false` — "as far as anybody knew, a person". A period
 * reaching back past that date reads its older mails the way the reports of the
 * day read them, which is the only answer available and better than a gap.
 */
class OpenRate extends EngagementRate
{
    public function handle(): string
    {
        return 'marketing.open_rate';
    }

    public function label(): string
    {
        return __('marketing::insights.open_rate');
    }

    public function description(): ?string
    {
        return __('marketing::insights.open_rate_description');
    }

    /**
     * The `machine` column as well, because this figure is defined by it.
     *
     * An install that has not run the 2026-08-15 migration cannot tell a person
     * from a proxy, and a rate that quietly counted both would be the number
     * this class exists to replace. Better no tile than the wrong one under the
     * right name.
     */
    public function available(): bool
    {
        return parent::available() && Schema::hasColumn('marketing_message_events', 'machine');
    }

    protected function engagedInPeriod(MetricQuery $query): Builder
    {
        return $this->withEvent($query, fn (Builder $events) => $this->humanOpen($events));
    }
}
