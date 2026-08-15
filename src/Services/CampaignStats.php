<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;

class CampaignStats
{
    /**
     * @return array{recipients:int, sent:int, failed:int, skipped:int, pending:int,
     *               opened:int, open_rate:float, clicked:int, click_rate:float,
     *               bounced:int, unsubscribed:int,
     *               variants:array<string, array<string, int|float>>}
     */
    public function forCampaign(Campaign $campaign): array
    {
        return $this->metrics(Message::forCampaign($campaign->handle)) + [
            'variants' => $this->variantBreakdown($campaign),
        ];
    }

    /**
     * The very same figures, for many campaigns, in two queries.
     *
     * {@see forCampaign()} costs about six queries and is exactly right for the
     * screen it was written for: one campaign, one report. The dashboard asks
     * the same question about a dozen campaigns at once, and asking it a dozen
     * times is seventy-odd queries for one page — an N+1 that no functional
     * test can see, because the page is correct either way.
     *
     * So this groups instead of looping: one aggregate over the messages
     * (`campaign_handle`, `variant`), one over the unsubscribe events. Two
     * queries for two campaigns, two for twelve, two for two hundred, and
     * DashboardQueryCountTest measures that rather than trusting it.
     *
     * It is deliberately NOT a replacement. `forCampaign()` is called from four
     * places, its figures are what every earlier campaign was measured with,
     * and a second definition of "open rate" in the same addon is the defect
     * this repo has already fixed three times. Both paths build their result
     * through {@see shape()}, so there is only one place where a rate is
     * defined, and CampaignStatsBundleTest holds the two against each other.
     *
     * @param  iterable<Campaign|string>  $campaigns  campaigns, or their handles
     * @return array<string, array{recipients:int, sent:int, failed:int, skipped:int,
     *               capped:int, pending:int, opened:int, open_rate:float, clicked:int,
     *               click_rate:float, bounced:int, unsubscribed:int,
     *               variants:array<string, array<string, int|float>>}> keyed by handle
     */
    public function forCampaigns(iterable $campaigns): array
    {
        $handles = collect($campaigns)
            ->map(fn ($campaign) => $campaign instanceof Campaign ? $campaign->handle : (string) $campaign)
            ->filter(fn (string $handle) => $handle !== '')
            ->unique()
            ->values();

        if ($handles->isEmpty()) {
            // Not merely an optimisation: `whereIn` with an empty list is a
            // query that can never match, and running two of them to learn
            // nothing is two queries this page does not have to spend.
            return [];
        }

        $messages = $this->groupedMessageCounts($handles->all());
        $unsubscribes = $this->groupedUnsubscribeCounts($handles->all());

        return $handles->mapWithKeys(function (string $handle) use ($messages, $unsubscribes) {
            $byVariant = $messages[$handle] ?? [];

            // The campaign's own figures are the sum across its variants —
            // including the unassigned bucket, which is where every campaign
            // that is not a split test keeps all of its messages.
            $totals = $this->sumCounts(array_values($byVariant));
            $totals['unsubscribed'] = array_sum($unsubscribes[$handle] ?? []);

            return [$handle => $this->shape($totals) + [
                'variants' => $this->shapeVariants($byVariant, $unsubscribes[$handle] ?? []),
            ]];
        })->all();
    }

    /**
     * The same figures again, once per A/B variant.
     *
     * Empty for every campaign that is not a split test, which is every
     * campaign that exists today — the key is always present so a consumer
     * never has to guess whether it looked.
     *
     * Nothing here reads an event table by variant, and that is the point.
     * Opens and clicks are recorded against a message, the variant lives on
     * that same message row, so narrowing the message set to one variant
     * narrows its opens, clicks, bounces and unsubscribes with it. The tracking
     * endpoints, their signed URLs and their parameter handling are untouched
     * by this feature: the variant is already implied by the message uuid the
     * pixel and the redirect carry, and adding it to those URLs would have
     * meant widening a surface that was hardened for a reason.
     *
     * No new personal data is stored or reported either. A variant label is a
     * bucket ('a' / 'b') on a row that already holds the recipient's address;
     * the per-variant figures are counts, and counts of what the addon already
     * counted.
     *
     * @return array<string, array<string, int|float>>
     */
    protected function variantBreakdown(Campaign $campaign): array
    {
        $assigned = Message::forCampaign($campaign->handle)
            ->whereNotNull('variant')
            ->distinct()
            ->pluck('variant')
            ->filter()
            ->sort()
            ->values();

        return $assigned
            ->mapWithKeys(function (string $variant) use ($campaign) {
                $metrics = $this->metrics(
                    Message::forCampaign($campaign->handle)->forVariant($variant)
                );

                return [$variant => $metrics + [
                    // Stated outright rather than left to be inferred, because
                    // it is the number that decides whether any of the rates
                    // above mean anything. `sample_size` is the delivered count
                    // — the denominator the rates are actually built on, which
                    // is not the same as `recipients` once anything failed,
                    // was skipped or is still pending.
                    //
                    // This report does not say which variant won, on purpose.
                    // Whether a gap between two rates is a result or noise is a
                    // question about the sample, and it is answered by the
                    // `ab-test-setup` skill, not by a column in a CP table.
                    'sample_size' => $metrics['sent'],
                ]];
            })
            ->all();
    }

    /**
     * @param  Builder  $base  a message query,
     *                         already narrowed to
     *                         whatever is being
     *                         measured
     * @return array{recipients:int, sent:int, failed:int, skipped:int, capped:int,
     *               pending:int, opened:int, open_rate:float, clicked:int,
     *               click_rate:float, bounced:int, unsubscribed:int}
     */
    protected function metrics($base): array
    {
        return $this->shape([
            'recipients' => (clone $base)->count(),
            'sent' => (clone $base)->where('status', Message::STATUS_SENT)->count(),
            'failed' => (clone $base)->where('status', Message::STATUS_FAILED)->count(),
            'skipped' => (clone $base)->where('status', Message::STATUS_SKIPPED)->count(),
            // Counted separately from `skipped`, because the two are different
            // answers to "why did this person not get it": skipped means the
            // address may not be mailed, capped means it may and was not.
            // Without a figure of its own the statuses stop adding up to
            // `recipients` and the report quietly loses people.
            'capped' => (clone $base)->where('status', Message::STATUS_CAPPED)->count(),
            'pending' => (clone $base)->where('status', Message::STATUS_PENDING)->count(),
            'opened' => (clone $base)->where('opens', '>', 0)->count(),
            'clicked' => (clone $base)->where('clicks', '>', 0)->count(),
            'bounced' => (clone $base)->where('status', Message::STATUS_BOUNCED)->count(),
            'unsubscribed' => MessageEvent::query()
                ->where('type', MessageEvent::TYPE_UNSUBSCRIBE)
                ->whereIn('message_id', (clone $base)->select('id'))
                ->count(),
        ]);
    }

    /**
     * Raw counts, turned into the figures a screen reads.
     *
     * The single place a rate is defined, and the reason it exists: the
     * per-campaign path counts with a dozen `count()` calls and the bundled
     * path counts with one `GROUP BY`, but neither of them decides what an open
     * rate is. If it were spelled twice, the two would eventually disagree by a
     * rounding step or a denominator, and the dashboard would quietly show a
     * different number than the campaign page for the same campaign.
     *
     * Delivered messages, not recipients, are the denominator — unchanged, and
     * the reason the figures stay comparable with every campaign sent before.
     *
     * @param  array<string, int>  $counts
     * @return array{recipients:int, sent:int, failed:int, skipped:int, capped:int,
     *               pending:int, opened:int, open_rate:float, clicked:int,
     *               click_rate:float, bounced:int, unsubscribed:int}
     */
    protected function shape(array $counts): array
    {
        $count = fn (string $key): int => (int) ($counts[$key] ?? 0);

        $sent = $count('sent');
        $opened = $count('opened');
        $clicked = $count('clicked');

        return [
            'recipients' => $count('recipients'),
            'sent' => $sent,
            'failed' => $count('failed'),
            'skipped' => $count('skipped'),
            'capped' => $count('capped'),
            'pending' => $count('pending'),
            'opened' => $opened,
            'open_rate' => $sent > 0 ? round($opened / $sent * 100, 1) : 0.0,
            'clicked' => $clicked,
            'click_rate' => $sent > 0 ? round($clicked / $sent * 100, 1) : 0.0,
            'bounced' => $count('bounced'),
            'unsubscribed' => $count('unsubscribed'),
        ];
    }

    /**
     * Every counted figure except the unsubscribes, per campaign and variant,
     * in one query.
     *
     * `SUM(CASE WHEN … )` rather than a dozen `count()` calls: the same twelve
     * numbers, read off one pass over the rows the campaigns already have.
     *
     * @param  list<string>  $handles
     * @return array<string, array<string, array<string, int>>> handle => variant => counts,
     *                                                          where the empty-string variant
     *                                                          holds every message that took no
     *                                                          part in a split test
     */
    protected function groupedMessageCounts(array $handles): array
    {
        $statuses = [
            'sent' => Message::STATUS_SENT,
            'failed' => Message::STATUS_FAILED,
            'skipped' => Message::STATUS_SKIPPED,
            'capped' => Message::STATUS_CAPPED,
            'pending' => Message::STATUS_PENDING,
            'bounced' => Message::STATUS_BOUNCED,
        ];

        $selects = ['campaign_handle', 'variant', 'COUNT(*) as recipients'];
        $bindings = [];

        foreach ($statuses as $key => $status) {
            $selects[] = "SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as {$key}";
            $bindings[] = $status;
        }

        $selects[] = 'SUM(CASE WHEN opens > 0 THEN 1 ELSE 0 END) as opened';
        $selects[] = 'SUM(CASE WHEN clicks > 0 THEN 1 ELSE 0 END) as clicked';

        $rows = Message::query()
            ->whereIn('campaign_handle', $handles)
            ->toBase()
            ->selectRaw(implode(', ', $selects), $bindings)
            ->groupBy('campaign_handle', 'variant')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $counts = ['recipients' => (int) $row->recipients];

            foreach (array_keys($statuses) as $key) {
                $counts[$key] = (int) $row->{$key};
            }

            $counts['opened'] = (int) $row->opened;
            $counts['clicked'] = (int) $row->clicked;

            $grouped[(string) $row->campaign_handle][(string) ($row->variant ?? '')] = $counts;
        }

        return $grouped;
    }

    /**
     * Unsubscribes per campaign and variant, in one query.
     *
     * Through a sub-select joined as a derived table, not a plain join on the
     * two tables. It is the established hop of this addon (see
     * {@see CampaignReport}) and the reason is the brand scope: applied to the
     * events on the outside and to the messages inside the sub-select, it rides
     * along on both halves. A bare join would carry only the events' half, and
     * `brand_id` exists on both tables, so it would also be ambiguous.
     *
     * @param  list<string>  $handles
     * @return array<string, array<string, int>> handle => variant => count
     */
    protected function groupedUnsubscribeCounts(array $handles): array
    {
        $messages = Message::query()
            ->whereIn('campaign_handle', $handles)
            ->toBase()
            ->select('id', 'campaign_handle', 'variant');

        $rows = MessageEvent::query()
            ->where('marketing_message_events.type', MessageEvent::TYPE_UNSUBSCRIBE)
            ->toBase()
            ->joinSub($messages, 'campaign_messages', 'campaign_messages.id', '=', 'marketing_message_events.message_id')
            ->selectRaw('campaign_messages.campaign_handle as campaign_handle, campaign_messages.variant as variant, COUNT(*) as aggregate')
            ->groupBy('campaign_messages.campaign_handle', 'campaign_messages.variant')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->campaign_handle][(string) ($row->variant ?? '')] = (int) $row->aggregate;
        }

        return $grouped;
    }

    /**
     * @param  list<array<string, int>>  $groups
     * @return array<string, int>
     */
    protected function sumCounts(array $groups): array
    {
        $totals = [];

        foreach ($groups as $group) {
            foreach ($group as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
        }

        return $totals;
    }

    /**
     * The per-variant block, built from figures that are already in memory.
     *
     * Same rules as {@see variantBreakdown()}: only assigned variants, sorted,
     * and each one carries the delivered count it was measured against.
     *
     * @param  array<string, array<string, int>>  $byVariant
     * @param  array<string, int>  $unsubscribes
     * @return array<string, array<string, int|float>>
     */
    protected function shapeVariants(array $byVariant, array $unsubscribes): array
    {
        $variants = array_values(array_filter(
            array_keys($byVariant),
            fn (string $variant): bool => $variant !== '',
        ));

        sort($variants);

        $shaped = [];

        foreach ($variants as $variant) {
            $counts = $byVariant[$variant];
            $counts['unsubscribed'] = $unsubscribes[$variant] ?? 0;

            $metrics = $this->shape($counts);

            $shaped[$variant] = $metrics + ['sample_size' => $metrics['sent']];
        }

        return $shaped;
    }

    /**
     * @return array{subscribed:int, pending:int, unsubscribed:int, bounced:int, complained:int, total:int}
     */
    public function forList(string $listHandle): array
    {
        $counts = Subscription::forList($listHandle)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'subscribed' => (int) $counts->get(Subscription::STATUS_SUBSCRIBED, 0),
            'pending' => (int) $counts->get(Subscription::STATUS_PENDING, 0),
            'unsubscribed' => (int) $counts->get(Subscription::STATUS_UNSUBSCRIBED, 0),
            'bounced' => (int) $counts->get(Subscription::STATUS_BOUNCED, 0),
            'complained' => (int) $counts->get(Subscription::STATUS_COMPLAINED, 0),
            'total' => (int) $counts->sum(),
        ];
    }
}
