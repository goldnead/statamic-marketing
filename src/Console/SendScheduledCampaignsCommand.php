<?php

namespace Goldnead\Marketing\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Services\CampaignSender;
use Illuminate\Console\Command;

class SendScheduledCampaignsCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'marketing:send-scheduled
        {--brand= : Restrict the run to one brand}';

    protected $description = 'Queue delivery for every scheduled campaign that is due';

    /**
     * A console run has no session, so no brand is current — and with
     * multi-brand on, both drivers then show nothing at all: the eloquent
     * scope fails closed, the flat store answers with an empty collection.
     * The scheduler would report "no campaigns due" every minute forever while
     * every scheduled campaign quietly missed its date. So the walk over
     * brands is explicit. Single-brand installs run the body once, unchanged.
     */
    public function handle(CampaignRepository $campaigns, CampaignSender $sender): int
    {
        return $this->forEachBrand(fn () => $this->queueDue($campaigns, $sender));
    }

    protected function queueDue(CampaignRepository $campaigns, CampaignSender $sender): int
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
