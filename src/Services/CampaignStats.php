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
     * @return array{recipients:int, sent:int, failed:int, skipped:int, pending:int,
     *               opened:int, open_rate:float, clicked:int, click_rate:float,
     *               bounced:int, unsubscribed:int}
     */
    protected function metrics($base): array
    {
        $recipients = (clone $base)->count();
        $sent = (clone $base)->where('status', Message::STATUS_SENT)->count();
        $opened = (clone $base)->where('opens', '>', 0)->count();
        $clicked = (clone $base)->where('clicks', '>', 0)->count();

        $unsubscribed = MessageEvent::query()
            ->where('type', MessageEvent::TYPE_UNSUBSCRIBE)
            ->whereIn('message_id', (clone $base)->select('id'))
            ->count();

        return [
            'recipients' => $recipients,
            'sent' => $sent,
            'failed' => (clone $base)->where('status', Message::STATUS_FAILED)->count(),
            'skipped' => (clone $base)->where('status', Message::STATUS_SKIPPED)->count(),
            'pending' => (clone $base)->where('status', Message::STATUS_PENDING)->count(),
            'opened' => $opened,
            'open_rate' => $sent > 0 ? round($opened / $sent * 100, 1) : 0.0,
            'clicked' => $clicked,
            'click_rate' => $sent > 0 ? round($clicked / $sent * 100, 1) : 0.0,
            'bounced' => (clone $base)->where('status', Message::STATUS_BOUNCED)->count(),
            'unsubscribed' => $unsubscribed,
        ];
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
