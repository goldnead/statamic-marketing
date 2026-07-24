<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Models\Subscription;

/**
 * P1 brand-scoping: hard consent isolation for marketing_subscriptions.
 *
 * The subscription table carries the double-opt-in / consent state per list.
 * Under multi-brand mode two brands must be able to hold fully independent
 * consent for the SAME email address, and neither may ever see the other's
 * rows. This guards against the single most expensive failure in the hub:
 * consent bleed across brands.
 */
beforeEach(function (): void {
    // Turn hard brand isolation ON for this file and reset any resolved brand.
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);
});

afterEach(function (): void {
    // This suite's RefreshDatabase reverses every migration in tearDown. Our
    // down() restores the pre-brand global unique (list_handle, email_normalized),
    // which the deliberately cross-brand-duplicated rows would violate. Clear
    // them across all brands so the reverse migration stays a faithful inverse.
    BrandContext::withoutBrandScope(fn () => Subscription::query()->delete());
});

it('hides a subscription/consent created in brand A from the brand B context', function (): void {
    // Brand A gains a confirmed subscriber.
    $subA = BrandContext::runFor($this->brandA, fn () => Subscription::create([
        'list_handle' => 'newsletter',
        'email' => 'shared@example.com',
        'status' => Subscription::STATUS_SUBSCRIBED,
        'confirmed_at' => now(),
    ]));

    expect($subA->brand_id)->toBe($this->brandA->id);

    // From inside brand B, brand A's subscriber does not exist.
    BrandContext::setCurrent($this->brandB);
    expect(Subscription::find($subA->id))->toBeNull();
    expect(Subscription::forList('newsletter')->count())->toBe(0);
    expect(Subscription::where('email', 'shared@example.com')->exists())->toBeFalse();

    // From inside brand A it is fully visible.
    BrandContext::setCurrent($this->brandA);
    expect(Subscription::find($subA->id))->not->toBeNull()
        ->and(Subscription::find($subA->id)->confirmed_at)->not->toBeNull();
});

it('lets the same email hold independent consent in brand A and brand B', function (): void {
    // Same address subscribes to the same list handle in BOTH brands.
    $subA = BrandContext::runFor($this->brandA, fn () => Subscription::create([
        'list_handle' => 'newsletter',
        'email' => 'shared@example.com',
        'status' => Subscription::STATUS_SUBSCRIBED,
        'confirmed_at' => now(),
    ]));

    // The new (brand_id, list_handle, email_normalized) unique must allow this;
    // the old (list_handle, email_normalized) unique would have thrown here.
    $subB = BrandContext::runFor($this->brandB, fn () => Subscription::create([
        'list_handle' => 'newsletter',
        'email' => 'shared@example.com',
        'status' => Subscription::STATUS_PENDING,
    ]));

    expect($subA->id)->not->toBe($subB->id)
        ->and($subA->brand_id)->toBe($this->brandA->id)
        ->and($subB->brand_id)->toBe($this->brandB->id);

    // Consent state is tracked separately per brand.
    BrandContext::setCurrent($this->brandA);
    $onlyA = Subscription::where('email', 'shared@example.com')->get();
    expect($onlyA)->toHaveCount(1)
        ->and($onlyA->first()->id)->toBe($subA->id)
        ->and($onlyA->first()->status)->toBe(Subscription::STATUS_SUBSCRIBED);

    BrandContext::setCurrent($this->brandB);
    $onlyB = Subscription::where('email', 'shared@example.com')->get();
    expect($onlyB)->toHaveCount(1)
        ->and($onlyB->first()->id)->toBe($subB->id)
        ->and($onlyB->first()->status)->toBe(Subscription::STATUS_PENDING);

    // Across all brands, both consents coexist.
    $all = BrandContext::withoutBrandScope(
        fn () => Subscription::where('email', 'shared@example.com')->count()
    );
    expect($all)->toBe(2);
});
