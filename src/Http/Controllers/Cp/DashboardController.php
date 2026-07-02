<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignStats;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request, MailingListRepository $lists, CampaignRepository $campaigns, CampaignStats $stats)
    {
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

        $recentCampaigns = $campaigns->all()
            ->filter(fn ($campaign) => ! $campaign->isDraft())
            ->take(5)
            ->map(fn ($campaign) => array_merge(
                [
                    'handle' => $campaign->handle,
                    'name' => $campaign->name,
                    'subject' => $campaign->subject,
                    'status' => $campaign->status,
                    'sent_at' => $campaign->sentAt?->toIso8601String(),
                    'url' => cp_route('marketing.campaigns.show', $campaign->handle),
                ],
                $stats->forCampaign($campaign),
            ))
            ->values()
            ->all();

        return Inertia::render('marketing::Dashboard', [
            'totalSubscribed' => Subscription::query()->subscribed()->count(),
            'totalPending' => Subscription::query()->where('status', Subscription::STATUS_PENDING)->count(),
            'listCount' => count($listRows),
            'lists' => $listRows,
            'recentCampaigns' => $recentCampaigns,
            'createCampaignUrl' => cp_route('marketing.campaigns.create'),
            'createListUrl' => cp_route('marketing.lists.create'),
        ]);
    }
}
