<?php

namespace Goldnead\Marketing\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many mails actually went out.
 *
 * On `sent_at`, which `SendMessageJob` writes when the transport accepted the
 * message and **clears again** when it did not: a failed send leaves the row
 * with a status and no date. So "has a `sent_at`" is the whole test for "was
 * delivered to the relay", and no status condition is needed beside it. A
 * message that bounced afterwards keeps its date and counts here, correctly —
 * it was sent, and what happened next is a different figure.
 *
 * Messages, not campaigns. Ten thousand recipients of one newsletter is ten
 * thousand mails, which is the number that matters against a sending quota, a
 * relay bill and an open rate. How the campaigns themselves did is
 * `CampaignStats`, which is untouched by this.
 *
 * The split is by campaign, and **a mail belonging to no campaign is a row**.
 * `campaign_handle` has been nullable since the template send mode arrived: a
 * single managed template sent to one person belongs to no campaign, and that
 * is the honest encoding rather than a placeholder handle. Dropping those rows
 * would make the split disagree with the total with nothing on the screen to
 * say why.
 */
class MailsSent extends MarketingMetric implements HasBreakdowns
{
    protected function table(): string
    {
        return 'marketing_messages';
    }

    protected function timestamp(): string
    {
        return 'sent_at';
    }

    public function handle(): string
    {
        return 'marketing.mails_sent';
    }

    public function label(): string
    {
        return __('marketing::insights.mails_sent');
    }

    public function description(): ?string
    {
        return __('marketing::insights.mails_sent_description');
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
        return ['campaign_handle' => __('marketing::insights.breakdown.campaign')];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'campaign_handle') {
            return [];
        }

        $rows = $this->splitByColumn($this->untilNow($query), $query, 'campaign_handle', 'count(*)', $limit);

        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] === null
                ? $this->missingLabel($dimension)
                : $this->campaignName($row['key']),
            'value' => $row['value'],
        ], $rows);
    }
}
