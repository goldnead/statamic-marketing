<?php

namespace Goldnead\Marketing\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Services\AbTest;
use Illuminate\Console\Command;

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

    /**
     * No lock here, on purpose. Whoever decides is settled by the database
     * ({@see AbTest::decide()}: one unique insert) and whoever re-queues a lost
     * winner pass by one conditional update ({@see AbTest::markDispatched()}),
     * so two runs on two hosts, or a cache that cannot lock, cannot both queue.
     */
    protected function sendDue(CampaignRepository $campaigns, AbTest $ab): int
    {
        $queued = 0;

        foreach ($campaigns->all() as $campaign) {
            if ($campaign->status !== Campaign::STATUS_SENDING) {
                continue;
            }

            if ($ab->isDue($campaign)) {
                $state = $ab->decide($campaigns, $campaign);

                if (! $state['claimed']) {
                    continue;
                }

                $row = $ab->decision($campaign->handle);
                $row && $ab->markDispatched($row, null);

                $this->queueWinner($campaign);
                $queued++;
                $this->info("Queued winner [{$state['winner']}] ({$state['reason']}) for campaign [{$campaign->handle}].");

                continue;
            }

            // The test pass itself died before it wrote its audience.
            if ($ab->needsTestResume($campaign)) {
                $ab->markTestQueued($campaigns, $campaign);
                StartCampaignJob::dispatch($campaign->handle)
                    ->onQueue((string) config('marketing.sending.queue', 'default'));
                $queued++;
                $this->info("Queued the test pass of campaign [{$campaign->handle}] again: it did not complete.");

                continue;
            }

            if ($ab->needsResume($campaign)) {
                $row = $ab->decision($campaign->handle);

                if ($row && $ab->markDispatched($row, $row->dispatched_at)) {
                    $this->queueWinner($campaign);
                    $queued++;
                    $this->info("Queued the winner pass of campaign [{$campaign->handle}] again: it did not complete.");
                }
            }
        }

        if ($queued === 0) {
            $this->line('No A/B winners due.');
        }

        return self::SUCCESS;
    }

    protected function queueWinner(Campaign $campaign): void
    {
        StartCampaignJob::dispatch($campaign->handle, true)
            ->onQueue((string) config('marketing.sending.queue', 'default'));
    }
}
