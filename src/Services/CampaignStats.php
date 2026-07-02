<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;

class CampaignStats
{
    /**
     * @return array{recipients:int, sent:int, failed:int, skipped:int, pending:int,
     *               opened:int, open_rate:float, clicked:int, click_rate:float,
     *               bounced:int, unsubscribed:int}
     */
    public function forCampaign(Campaign $campaign): array
    {
        $base = Message::forCampaign($campaign->handle);

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
