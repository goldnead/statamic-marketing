<?php

namespace Goldnead\Marketing\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Marketing\Series\SeriesSync;
use Illuminate\Console\Command;

class SeriesSyncCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'marketing:series-sync
        {--brand= : Restrict the run to one brand}';

    protected $description = 'Erzeugt aus Serien-Vorlagen die Kampagnen kommender Termine und räumt abgesagte weg';

    /**
     * A console run has no session, so no brand is current — the same reason
     * `marketing:send-scheduled` walks the brands explicitly: templates,
     * occurrences and segments are all brand-scoped, and with multi-brand on
     * a brandless query answers nothing at all.
     */
    public function handle(SeriesSync $sync): int
    {
        if (! SeriesSync::available()) {
            $this->warn(__('marketing::series.not_installed'));

            return self::SUCCESS;
        }

        return $this->forEachBrand(function () use ($sync): int {
            $result = $sync->syncAll();

            $this->info(__('marketing::series.summary', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'removed' => $result['removed'],
                'skipped' => $result['skipped_no_postal_code'],
                'presale' => $result['skipped_no_presale'],
                'late' => $result['skipped_too_late'],
            ]));

            return self::SUCCESS;
        });
    }
}
