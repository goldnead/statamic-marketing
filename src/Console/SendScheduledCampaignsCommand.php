<?php

namespace Goldnead\Marketing\Console;

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Services\CampaignSender;
use Illuminate\Console\Command;

class SendScheduledCampaignsCommand extends Command
{
    protected $signature = 'marketing:send-scheduled';

    protected $description = 'Queue delivery for every scheduled campaign that is due';

    public function handle(CampaignRepository $campaigns, CampaignSender $sender): int
    {
        $due = $campaigns->due(now());

        foreach ($due as $campaign) {
            $sender->queue($campaign);
            $this->info("Queued campaign [{$campaign->handle}].");
        }

        if ($due->isEmpty()) {
            $this->line('No campaigns due.');
        }

        return self::SUCCESS;
    }
}
