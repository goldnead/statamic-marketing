<?php

namespace Goldnead\Marketing\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many people left.
 *
 * On `unsubscribed_at`, which `SubscriptionService::unsubscribe()` writes on
 * every route out — the footer link, the preference centre, an editor removing
 * somebody. It is the complete column, which is why it is read rather than the
 * unsubscribe *events*: those are never rewritten, but one is only recorded
 * where the unsubscribe carried a message, so every sign-off from the
 * preference centre would be missing from the figure.
 *
 * **The caveat, which `SubscriptionGrowth` already states and which this
 * inherits unchanged: the column is cleared when the same address subscribes
 * again.** Somebody who left in May and came back in July does not appear in
 * May at all, and May's figure drops on the day they return. This is a picture
 * of the list as it stands, not a ledger of everything that ever happened to
 * it, and the honest thing is to say so rather than to imply a history the
 * schema does not keep.
 *
 * **A bounce and a complaint are not in this figure**, and that is the schema's
 * doing rather than a choice: `EspEventProcessor` moves such a subscription to
 * `bounced` or `complained` and writes no date anywhere, so there is nothing to
 * place in a period. They are ends of a subscription that this figure cannot
 * see. `CampaignReport` shows them per campaign, where a message dates them.
 */
class Unsubscribed extends MarketingMetric implements HasBreakdowns
{
    protected function table(): string
    {
        return 'marketing_subscriptions';
    }

    protected function timestamp(): string
    {
        return 'unsubscribed_at';
    }

    public function handle(): string
    {
        return 'marketing.unsubscribed';
    }

    public function label(): string
    {
        return __('marketing::insights.unsubscribed');
    }

    public function description(): ?string
    {
        return __('marketing::insights.unsubscribed_description');
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

        return (int) $this->untilNow($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->untilNow($query), $query, 'count(*)'),
        );
    }

    public function breakdowns(): array
    {
        return ['list_handle' => __('marketing::insights.breakdown.list')];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'list_handle') {
            return [];
        }

        $rows = $this->splitByColumn($this->untilNow($query), $query, 'list_handle', 'count(*)', $limit);

        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] === null
                ? $this->missingLabel($dimension)
                : $this->listName($row['key']),
            'value' => $row['value'],
        ], $rows);
    }
}
