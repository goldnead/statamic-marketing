<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Illuminate\Support\Facades\Queue;

/**
 * `marketing:send-scheduled` under multi-brand.
 *
 * A console run has no session, so no brand is current — and with multi-brand
 * on, both drivers then answer with nothing: the eloquent scope fails closed,
 * and the flat store, now that it has a brand boundary, does the same. The
 * scheduler would print "No campaigns due." every minute forever while every
 * scheduled campaign silently missed its date. That is the failure mode
 * brand-context's RunsForEachBrand exists for, and the flat driver needs it as
 * much as the eloquent one does.
 */
beforeEach(function (): void {
    config()->set('marketing.storage.driver', 'flat');
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $lists = app(MailingListRepository::class);
    $campaigns = app(CampaignRepository::class);

    foreach ([['brand-a', $this->brandA], ['brand-b', $this->brandB]] as [$slug, $brand]) {
        BrandContext::runFor($brand, function () use ($lists, $campaigns, $slug): void {
            $handle = str_replace('-', '_', $slug);

            $lists->save(new MailingList(handle: $handle.'_list', name: 'List'));
            $campaigns->save(new Campaign(
                handle: $handle.'_due',
                name: 'Due',
                subject: 'Due',
                listHandle: $handle.'_list',
                status: Campaign::STATUS_SCHEDULED,
                scheduledAt: now()->subMinute()->toImmutable(),
            ));
        });
    }

    BrandContext::forget();
});

it('queues the due campaign of every brand, not just the default one', function (): void {
    Queue::fake();

    $this->artisan('marketing:send-scheduled')->assertSuccessful();

    $queued = [];

    Queue::assertPushed(StartCampaignJob::class, function ($job) use (&$queued) {
        $queued[] = $job->campaignHandle ?? null;

        return true;
    });

    sort($queued);

    expect($queued)->toBe(['brand_a_due', 'brand_b_due']);
});

it('runs only the brand it is told to', function (): void {
    Queue::fake();

    $this->artisan('marketing:send-scheduled', ['--brand' => 'brand-b'])->assertSuccessful();

    Queue::assertPushed(StartCampaignJob::class, 1);
});
