<?php

namespace Goldnead\Marketing\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Services\AbTest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendAbWinnersCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'marketing:send-ab-winners
        {--brand= : Restrict the run to one brand}';

    protected $description = 'Send the winning subject of every A/B test whose wait is over to the rest of the audience';

    public function handle(CampaignRepository $campaigns, AbTest $ab): int
    {
        return $this->forEachBrand(fn () => $this->sendDue($campaigns, $ab));
    }

    protected function sendDue(CampaignRepository $campaigns, AbTest $ab): int
    {
        $queued = 0;

        foreach ($campaigns->all() as $campaign) {
            if ($campaign->status !== Campaign::STATUS_SENDING || ! $ab->isDue($campaign)) {
                continue;
            }

            // The scheduler entry is withoutOverlapping, but this command can
            // also be run by hand beside it. The phase written by decide() is
            // the real guard; the lock only keeps two runs from deciding at
            // the same instant.
            $lock = Cache::lock("marketing-ab-winner:{$campaign->handle}", 300);

            if (! $lock->get()) {
                continue;
            }

            try {
                $fresh = $campaigns->find($campaign->handle);

                if (! $fresh || ! $ab->isDue($fresh)) {
                    continue;
                }

                $state = $ab->decide($campaigns, $fresh);

                try {
                    StartCampaignJob::dispatch($fresh->handle, true)
                        ->onQueue((string) config('marketing.sending.queue', 'default'));
                } catch (Throwable $e) {
                    // Nothing went out: take the decision back so the next
                    // run decides and queues again.
                    $ab->reopen($campaigns, $fresh);

                    throw $e;
                }

                $queued++;
                $this->info("Queued winner [{$state['winner']}] ({$state['reason']}) for campaign [{$fresh->handle}].");
            } finally {
                $lock->release();
            }
        }

        if ($queued === 0) {
            $this->line('No A/B winners due.');
        }

        return self::SUCCESS;
    }
}
