<?php

namespace Goldnead\Marketing\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many people confirmed a subscription.
 *
 * On `confirmed_at`, which is when consent became real. Both paths through
 * `SubscriptionService` write it: the double-opt-in link, and the single-opt-in
 * or editor-added sign-up that never needed one. So a confirmation is dated
 * wherever this addon made one.
 *
 * **This is not `SubscriptionGrowth`, and the difference is deliberate rather
 * than an accident of two people writing similar queries.** That chart counts
 * sign-*ups* on `COALESCE(subscribed_at, created_at)` — somebody filled in the
 * form, confirmed or not — because "did people join this week" is what it is
 * asked. This counts confirmations, because a figure sitting beside revenue is
 * asked how many people are now actually reachable. Two different questions,
 * two different columns, and naming them differently is what keeps them from
 * being read as the same number that disagrees with itself.
 *
 * The caveat that comes with `confirmed_at` and belongs in the open: it is NULL
 * on rows that arrived by import and on any that predate the column, so their
 * consent has no date and cannot appear in any period. Those rows are real and
 * this addon ships `php artisan marketing:consent-integrity` to find them. The
 * alternative — falling back to another column — would date a consent by
 * something that is not the consent, which is worse on a figure that exists to
 * be defensible.
 *
 * Split by list, because "forty new subscribers" and "forty new subscribers,
 * thirty-eight of them on the one list" are different pieces of news.
 */
class Subscribed extends MarketingMetric implements HasBreakdowns
{
    protected function table(): string
    {
        return 'marketing_subscriptions';
    }

    protected function timestamp(): string
    {
        return 'confirmed_at';
    }

    public function handle(): string
    {
        return 'marketing.subscribed';
    }

    public function label(): string
    {
        return __('marketing::insights.subscribed');
    }

    public function description(): ?string
    {
        return __('marketing::insights.subscribed_description');
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
