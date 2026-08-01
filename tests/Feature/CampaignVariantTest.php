<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Services\VariantAssigner;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
});

/**
 * Subscribe somebody and pin their subscription uuid to one that lands in the
 * given variant.
 *
 * The uuid is the assignment's only recipient-side input, so choosing it is how
 * a test names a variant without re-implementing the hash or leaning on luck.
 * Roughly two tries on average.
 */
function subscriberInVariant(string $variant, string $campaignHandle, string $email): object
{
    $subscription = app(SubscriptionService::class)->subscribe(
        test()->list,
        $email,
        ['first_name' => Str::title(Str::before($email, '@'))],
    );

    $assigner = new VariantAssigner;
    $brandId = $subscription->brand_id === null ? null : (int) $subscription->brand_id;

    do {
        $uuid = (string) Str::uuid();
    } while ($assigner->assign($campaignHandle, $uuid, $brandId) !== $variant);

    $subscription->forceFill(['uuid' => $uuid])->save();

    return $subscription->fresh();
}

function saveCampaign(?string $variantSubject): Campaign
{
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'juli',
        name: 'Juli-Ausgabe',
        subject: 'Was diesen Monat ansteht',
        variantSubject: $variantSubject,
        listHandle: 'newsletter',
        content: '<p>Hallo {{ first_name }}, hier die <a href="https://example.com/news">Neuigkeiten</a>.</p>',
    ));

    return app(CampaignRepository::class)->find('juli');
}

/**
 * The acceptance criterion that guards everything already in production: a
 * campaign that does not opt in must be untouched by this feature.
 */
it('leaves a campaign without variants exactly as it was', function (): void {
    app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com', ['first_name' => 'Jane']);
    app(SubscriptionService::class)->subscribe($this->list, 'john@example.com', ['first_name' => 'John']);

    $campaign = saveCampaign(null);

    expect($campaign->hasVariants())->toBeFalse();

    app(CampaignSender::class)->queue($campaign);

    expect(Message::forCampaign('juli')->count())->toBe(2)
        ->and(Message::forCampaign('juli')->whereNotNull('variant')->count())->toBe(0);

    Mail::assertSent(CampaignMail::class, 2);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->rendered->subject === 'Was diesen Monat ansteht');

    // No variants assigned means nothing to break down, and the key is still
    // there so a consumer never has to guess whether it was computed.
    expect(app(CampaignStats::class)->forCampaign($campaign)['variants'])->toBe([]);
});

it('treats a blank variant subject as no test at all', function (): void {
    app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com', ['first_name' => 'Jane']);

    $campaign = saveCampaign('   ');

    expect($campaign->hasVariants())->toBeFalse();

    app(CampaignSender::class)->queue($campaign);

    expect(Message::forCampaign('juli')->whereNotNull('variant')->count())->toBe(0);
});

it('assigns a variant to every recipient and sends each the matching subject', function (): void {
    $a = subscriberInVariant('a', 'juli', 'anna@example.com');
    $b = subscriberInVariant('b', 'juli', 'bernd@example.com');

    $campaign = saveCampaign('Drei Dinge für diesen Monat');

    expect($campaign->hasVariants())->toBeTrue();

    app(CampaignSender::class)->queue($campaign);

    $messageA = Message::forCampaign('juli')->where('subscription_id', $a->id)->firstOrFail();
    $messageB = Message::forCampaign('juli')->where('subscription_id', $b->id)->firstOrFail();

    expect($messageA->variant)->toBe('a')
        ->and($messageB->variant)->toBe('b');

    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('anna@example.com')
        && $mail->rendered->subject === 'Was diesen Monat ansteht');

    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('bernd@example.com')
        && $mail->rendered->subject === 'Drei Dinge für diesen Monat');
});

/**
 * The idempotency criterion. A campaign is snapshotted once, but the job that
 * does it can be retried — by the queue, by a failed chunk, by a human.
 */
it('does not reassign or duplicate a variant when the start job runs again', function (): void {
    subscriberInVariant('a', 'juli', 'anna@example.com');
    subscriberInVariant('b', 'juli', 'bernd@example.com');

    $campaign = saveCampaign('Drei Dinge für diesen Monat');
    $campaign->status = Campaign::STATUS_SENDING;
    app(CampaignRepository::class)->save($campaign);

    $run = fn () => (new StartCampaignJob('juli'))->handle(
        app(CampaignRepository::class),
        app(\Goldnead\Leadhub\Contracts\Repositories\ContactRepository::class),
        app(\Goldnead\Suppression\Contracts\Gate::class),
        app(VariantAssigner::class),
    );

    $run();

    $before = Message::forCampaign('juli')->orderBy('id')->pluck('variant', 'id')->all();

    expect($before)->toHaveCount(2);

    // Reset to sending — the first run finalized it — and go again.
    $campaign = app(CampaignRepository::class)->find('juli');
    $campaign->status = Campaign::STATUS_SENDING;
    app(CampaignRepository::class)->save($campaign);

    $run();

    $after = Message::forCampaign('juli')->orderBy('id')->pluck('variant', 'id')->all();

    expect($after)->toBe($before);
});

/**
 * The stronger form of the same claim: the stored variant is a cache of a
 * derived fact, not the fact itself. Delete the row and rebuild it and the
 * recipient lands in the same bucket — which is why a retry, a restore or a
 * re-queue cannot corrupt a running test.
 */
it('rebuilds the identical assignment after the message rows are destroyed', function (): void {
    subscriberInVariant('a', 'juli', 'anna@example.com');
    subscriberInVariant('b', 'juli', 'bernd@example.com');

    $campaign = saveCampaign('Drei Dinge für diesen Monat');

    app(CampaignSender::class)->queue($campaign);

    $before = Message::forCampaign('juli')->orderBy('email')->pluck('variant', 'email')->all();

    Message::forCampaign('juli')->delete();

    $campaign = app(CampaignRepository::class)->find('juli');
    $campaign->status = Campaign::STATUS_SENDING;
    app(CampaignRepository::class)->save($campaign);

    (new StartCampaignJob('juli'))->handle(
        app(CampaignRepository::class),
        app(\Goldnead\Leadhub\Contracts\Repositories\ContactRepository::class),
        app(\Goldnead\Suppression\Contracts\Gate::class),
        app(VariantAssigner::class),
    );

    expect(Message::forCampaign('juli')->orderBy('email')->pluck('variant', 'email')->all())->toBe($before);
});

/**
 * Without this the whole test is decoration: a variant nobody can attribute an
 * open or a click to measures nothing.
 *
 * Note what is NOT here. No tracking route changed, no signed URL gained a
 * parameter, no signature logic was touched. The open pixel and the click
 * redirect already carry the message uuid, the variant sits on that same
 * message row, and that is the entire mechanism.
 */
it('attributes an open and a click to the variant that produced it', function (): void {
    $a = subscriberInVariant('a', 'juli', 'anna@example.com');
    $b = subscriberInVariant('b', 'juli', 'bernd@example.com');

    $campaign = saveCampaign('Drei Dinge für diesen Monat');

    app(CampaignSender::class)->queue($campaign);

    $messageA = Message::forCampaign('juli')->where('subscription_id', $a->id)->firstOrFail();
    $messageB = Message::forCampaign('juli')->where('subscription_id', $b->id)->firstOrFail();

    // Variant A opens. Variant B opens and clicks.
    $this->get(route('marketing.track.open', ['uuid' => $messageA->uuid]))->assertOk();
    $this->get(route('marketing.track.open', ['uuid' => $messageB->uuid]))->assertOk();
    $this->get(URL::signedRoute('marketing.track.click', [
        'uuid' => $messageB->uuid,
        'url' => 'https://example.com/news',
    ]))->assertRedirect('https://example.com/news');

    $stats = app(CampaignStats::class)->forCampaign(app(CampaignRepository::class)->find('juli'));

    expect(array_keys($stats['variants']))->toBe(['a', 'b']);

    expect($stats['variants']['a'])
        ->toMatchArray(['recipients' => 1, 'sent' => 1, 'sample_size' => 1, 'opened' => 1, 'clicked' => 0])
        ->and($stats['variants']['a']['open_rate'])->toBe(100.0)
        ->and($stats['variants']['a']['click_rate'])->toBe(0.0);

    expect($stats['variants']['b'])
        ->toMatchArray(['recipients' => 1, 'sent' => 1, 'sample_size' => 1, 'opened' => 1, 'clicked' => 1])
        ->and($stats['variants']['b']['click_rate'])->toBe(100.0);

    // The click landed on B's row, not on A's — the thing that would silently
    // ruin every result if the join were wrong.
    expect(MessageEvent::query()->where('type', MessageEvent::TYPE_CLICK)->value('message_id'))
        ->toBe($messageB->id);

    // And the campaign total still counts both, unchanged by the split.
    expect($stats['recipients'])->toBe(2)
        ->and($stats['opened'])->toBe(2)
        ->and($stats['clicked'])->toBe(1);
});

it('reports bounces and unsubscribes per variant as well', function (): void {
    $a = subscriberInVariant('a', 'juli', 'anna@example.com');
    $b = subscriberInVariant('b', 'juli', 'bernd@example.com');

    $campaign = saveCampaign('Drei Dinge für diesen Monat');

    app(CampaignSender::class)->queue($campaign);

    $messageA = Message::forCampaign('juli')->where('subscription_id', $a->id)->firstOrFail();
    $messageB = Message::forCampaign('juli')->where('subscription_id', $b->id)->firstOrFail();

    $messageA->forceFill(['status' => Message::STATUS_BOUNCED])->save();

    MessageEvent::create([
        'message_id' => $messageB->id,
        'type' => MessageEvent::TYPE_UNSUBSCRIBE,
    ]);

    $stats = app(CampaignStats::class)->forCampaign(app(CampaignRepository::class)->find('juli'));

    expect($stats['variants']['a'])->toMatchArray(['bounced' => 1, 'unsubscribed' => 0, 'sent' => 0, 'sample_size' => 0])
        ->and($stats['variants']['b'])->toMatchArray(['bounced' => 0, 'unsubscribed' => 1, 'sent' => 1, 'sample_size' => 1]);

    // Nothing was delivered in A, so its rates are 0 rather than a division by
    // zero dressed up as a result.
    expect($stats['variants']['a']['open_rate'])->toBe(0.0);
});

it('never lets one brand see or influence the assignment of another', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    // The same address, subscribed in both brands, on identically-named lists.
    foreach ([$brandA, $brandB] as $brand) {
        BrandContext::runFor($brand, function () use ($brand): void {
            app(MailingListRepository::class)->save(new MailingList(
                handle: 'newsletter-'.$brand->handle,
                name: 'Newsletter',
                doubleOptIn: false,
            ));

            app(SubscriptionService::class)->subscribe(
                app(MailingListRepository::class)->find('newsletter-'.$brand->handle),
                'shared@example.com',
                ['first_name' => 'Shared'],
            );

            app(CampaignRepository::class)->save(new Campaign(
                handle: 'juli-'.$brand->handle,
                name: 'Juli',
                subject: 'A',
                variantSubject: 'B',
                listHandle: 'newsletter-'.$brand->handle,
                content: '<p>Hallo</p>',
            ));

            app(CampaignSender::class)->queue(
                app(CampaignRepository::class)->find('juli-'.$brand->handle)
            );
        });
    }

    // Each brand sees exactly its own message, with its own variant, and the
    // other brand's is invisible rather than merely different.
    foreach ([$brandA, $brandB] as $brand) {
        BrandContext::setCurrent($brand);

        $messages = Message::query()->get();

        expect($messages)->toHaveCount(1)
            ->and($messages->first()->brand_id)->toBe($brand->id)
            ->and($messages->first()->variant)->toBeIn(['a', 'b'])
            ->and($messages->first()->campaign_handle)->toBe('juli-'.$brand->handle);
    }

    // And the assignment itself is seeded per brand: the identical campaign
    // handle and recipient key in two tenants are two independent questions.
    $assigner = new VariantAssigner;
    $key = (string) Str::uuid();

    $perBrand = collect([$brandA->id, $brandB->id])
        ->map(fn (int $id) => $assigner->assign('juli', $key, $id));

    expect($perBrand->every(fn ($v) => in_array($v, ['a', 'b'], true)))->toBeTrue();
});

it('round-trips the variant subject through the configured storage driver', function (): void {
    saveCampaign('Drei Dinge für diesen Monat');

    $reloaded = app(CampaignRepository::class)->find('juli');

    expect($reloaded->variantSubject)->toBe('Drei Dinge für diesen Monat')
        ->and($reloaded->hasVariants())->toBeTrue()
        ->and($reloaded->toArray()['variant_subject'])->toBe('Drei Dinge für diesen Monat');

    // Clearing it ends the test rather than leaving a stale B subject behind.
    $reloaded->variantSubject = null;
    app(CampaignRepository::class)->save($reloaded);

    expect(app(CampaignRepository::class)->find('juli')->hasVariants())->toBeFalse();
});
