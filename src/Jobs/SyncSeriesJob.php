<?php

namespace Goldnead\Marketing\Jobs;

use Goldnead\Marketing\Series\SeriesSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One full series sync, queued and bundled.
 *
 * The term events used to run {@see SeriesSync::syncAll()} right inside the
 * CP request that saved the date — templates × dates², a postal code lookup
 * per venue — and an import of thirty dates ran it thirty times. Now every
 * term event dispatches this, after the commit, and `ShouldBeUnique` keeps
 * one per brand in the queue for a few seconds: thirty events, one run, and
 * the run sees all thirty dates because it starts after them.
 *
 * With the `sync` queue driver it simply runs at once, as before. The daily
 * `marketing:series-sync` stays the safety net either way.
 *
 * The brand travels with the job: a queue worker has no brand in context,
 * and campaigns, segments and dates are all brand-scoped.
 */
class SyncSeriesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds the bundle stays open: events inside it share one run. */
    public int $uniqueFor = 10;

    public function __construct(public ?int $brandId = null) {}

    public function uniqueId(): string
    {
        return 'marketing-series-sync-'.($this->brandId ?? 'none');
    }

    public function handle(SeriesSync $sync): void
    {
        if ($this->brandId !== null && app()->bound('brand-context')) {
            app('brand-context')->runFor($this->brandId, fn () => $sync->syncAll());

            return;
        }

        $sync->syncAll();
    }
}
