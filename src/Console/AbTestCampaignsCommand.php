<?php

namespace Goldnead\Marketing\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Illuminate\Console\Command;

/**
 * Read only. Lists the campaigns that will send as an A/B test with a winner
 * pass: a variant subject and a test share, not sent yet.
 *
 * Before the winner send existed such a campaign went to the whole audience,
 * half and half. Run this before updating, and decide per campaign whether to
 * set its test share to 0 (the old behaviour) or leave it.
 */
class AbTestCampaignsCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'marketing:ab-campaigns
        {--brand= : Restrict the run to one brand}';

    protected $description = 'List unsent campaigns that will run as an A/B test with a winner send (read only)';

    public function handle(CampaignRepository $campaigns): int
    {
        return $this->forEachBrand(function () use ($campaigns): int {
            $affected = $campaigns->all()->filter(
                fn (Campaign $c) => $c->hasTestShare() && ! in_array($c->status, [Campaign::STATUS_SENT], true)
            );

            foreach ($affected as $campaign) {
                $this->line("{$campaign->handle}\t{$campaign->status}\tab_share={$campaign->abShare}");
            }

            $this->info($affected->count().' campaign(s) will send as an A/B test with a winner pass.');

            return self::SUCCESS;
        });
    }
}
