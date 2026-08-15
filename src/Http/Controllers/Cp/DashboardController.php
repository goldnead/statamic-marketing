<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Services\SubscriptionGrowth;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * How many sent campaigns the engagement curve looks back over.
     *
     * A quarter's worth for anybody sending weekly. Fewer would not show a
     * direction; many more would put the labels closer together than the bars.
     */
    public const TREND_CAMPAIGNS = 12;

    /** The summary table above the charts. Unchanged. */
    public const RECENT_CAMPAIGNS = 5;

    public function index(
        Request $request,
        MailingListRepository $lists,
        CampaignRepository $campaigns,
        CampaignStats $stats,
        SubscriptionGrowth $growth,
    ) {
        $this->authorizeOrFail($request, 'view marketing');

        $listRows = $lists->all()->map(function ($list) use ($stats) {
            $listStats = $stats->forList($list->handle);

            return [
                'handle' => $list->handle,
                'name' => $list->name,
                'subscribed' => $listStats['subscribed'],
                'pending' => $listStats['pending'],
                'url' => cp_route('marketing.lists.show', $list->handle),
            ];
        })->values()->all();

        $all = $campaigns->all();

        // The table wants the latest campaigns whatever their state; the curve
        // wants only the ones that actually went out, because an open rate on a
        // campaign that was never sent is a nought pretending to be a result.
        $recent = $all->filter(fn (Campaign $campaign) => ! $campaign->isDraft())
            ->take(self::RECENT_CAMPAIGNS)
            ->values();

        $sent = $all->filter(fn (Campaign $campaign) => $campaign->sentAt !== null)
            ->take(self::TREND_CAMPAIGNS)
            ->values();

        /*
         * One bundled lookup for both, rather than one per campaign.
         *
         * The loop this replaces called CampaignStats::forCampaign() per row —
         * about six queries each, so five rows cost thirty and the twelve the
         * curve needs would have cost seventy-odd for a single page. Nothing
         * about the page looked wrong; it was just slow in a way that grows
         * with the customer's history. `forCampaigns()` answers for all of them
         * in two queries, and DashboardQueryCountTest measures that the number
         * does not move between two campaigns and twelve.
         */
        $figures = $stats->forCampaigns($recent->concat($sent));

        return Inertia::render('marketing::Dashboard', [
            'totalSubscribed' => Subscription::query()->subscribed()->count(),
            'totalPending' => Subscription::query()->where('status', Subscription::STATUS_PENDING)->count(),
            'listCount' => count($listRows),
            'lists' => $listRows,
            'recentCampaigns' => $this->recentRows($recent, $figures),
            'engagement' => $this->engagementRows($sent, $figures),
            'growth' => $growth->weekly(),
            'createCampaignUrl' => cp_route('marketing.campaigns.create'),
            'createListUrl' => cp_route('marketing.lists.create'),
        ]);
    }

    /**
     * @param  Collection<int, Campaign>  $campaigns
     * @param  array<string, array<string, mixed>>  $figures
     * @return list<array<string, mixed>>
     */
    protected function recentRows(Collection $campaigns, array $figures): array
    {
        return $campaigns->map(fn (Campaign $campaign) => array_merge(
            [
                'handle' => $campaign->handle,
                'name' => $campaign->name,
                'subject' => $campaign->subject,
                'status' => $campaign->status,
                'sent_at' => $campaign->sentAt?->toIso8601String(),
                'url' => cp_route('marketing.campaigns.show', $campaign->handle),
            ],
            $figures[$campaign->handle] ?? [],
        ))->values()->all();
    }

    /**
     * The engagement curve: open and click rate per campaign, oldest first.
     *
     * The rates are lifted straight out of {@see CampaignStats} — the same
     * numbers the campaign's own report prints, not a second calculation that
     * happens to agree today. Two different open rates in one addon is a defect
     * this repo has already shipped and fixed, and the way it happens is
     * exactly here: a chart that "just needs a percentage" and works one out.
     *
     * Reversed into send order, because a trend is read left to right and the
     * repository hands campaigns back newest first.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @param  array<string, array<string, mixed>>  $figures
     * @return list<array<string, mixed>>
     */
    protected function engagementRows(Collection $campaigns, array $figures): array
    {
        return $campaigns
            ->reverse()
            ->map(function (Campaign $campaign) use ($figures) {
                $stats = $figures[$campaign->handle] ?? [];

                return [
                    'handle' => $campaign->handle,
                    'name' => $campaign->name,
                    'sent_at' => $campaign->sentAt?->toIso8601String(),
                    'url' => cp_route('marketing.campaigns.show', $campaign->handle),
                    'open_rate' => (float) ($stats['open_rate'] ?? 0.0),
                    'click_rate' => (float) ($stats['click_rate'] ?? 0.0),
                    // The denominator, carried along. A 100% open rate on four
                    // delivered messages is not the same claim as one on four
                    // thousand, and a bar chart cannot say so on its own.
                    'sent' => (int) ($stats['sent'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }
}
