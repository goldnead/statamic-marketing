<?php

namespace Goldnead\Marketing\Integrations\Insights;

use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Illuminate\Database\Query\Builder;

/**
 * How many of the mails sent had a link followed.
 *
 * The machine question does not arise here and it is worth saying why rather
 * than leaving the asymmetry with {@see OpenRate} looking like an oversight: a
 * privacy proxy fetches images, it does not follow links. A click is the one
 * signal in email that a person was there, which is exactly why the open figure
 * beside this one counts a click as a reader.
 *
 * Counted once per message, like everything else here: somebody who followed
 * three links is one reader, not three.
 */
class ClickRate extends EngagementRate
{
    public function handle(): string
    {
        return 'marketing.click_rate';
    }

    public function label(): string
    {
        return __('marketing::insights.click_rate');
    }

    public function description(): ?string
    {
        return __('marketing::insights.click_rate_description');
    }

    protected function engagedInPeriod(MetricQuery $query): Builder
    {
        return $this->withEvent(
            $query,
            fn (Builder $events) => $events->where('type', MessageEvent::TYPE_CLICK),
        );
    }
}
