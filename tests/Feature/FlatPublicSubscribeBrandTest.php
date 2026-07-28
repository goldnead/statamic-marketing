<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;

/**
 * The public subscribe endpoint on the flat driver, under multi-brand.
 *
 * This is the case that used to be impossible. The brand comes from a token
 * for every other public route, but a subscribe form carries no token — it
 * carries a list handle, and until 1.6 a list handle could only be traced back
 * to a brand through an Eloquent model that the flat driver does not have. So
 * flat multi-brand installs had no working sign-up at all: no brand resolved,
 * the store failed closed, and the list the form named did not exist.
 *
 * Every request here is made with no brand current, on purpose. That is the
 * situation a stranger's browser produces.
 */
beforeEach(function (): void {
    config()->set('marketing.storage.driver', 'flat');
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $lists = app(MailingListRepository::class);

    BrandContext::runFor($this->brandA, fn () => $lists->save(
        new MailingList(handle: 'a_news', name: 'A News', doubleOptIn: false)
    ));

    BrandContext::runFor($this->brandB, fn () => $lists->save(
        new MailingList(handle: 'b_news', name: 'B News', doubleOptIn: false)
    ));

    BrandContext::forget();
});

afterEach(function (): void {
    BrandContext::withoutBrandScope(fn () => Subscription::query()->delete());
});

it('lands a sign-up in the brand that owns the list, not in the default one', function (): void {
    $this->post('/!/marketing/subscribe', [
        'email' => 'newcomer@example.com',
        'list' => 'b_news',
    ])->assertRedirect();

    $created = BrandContext::withoutBrandScope(
        fn () => Subscription::query()->where('email', 'newcomer@example.com')->first()
    );

    expect($created)->not->toBeNull()
        ->and($created->brand_id)->toBe($this->brandB->id)
        ->and($created->list_handle)->toBe('b_news');
});

it('sends each list\'s sign-up to its own brand — the security boundary', function (): void {
    $this->post('/!/marketing/subscribe', ['email' => 'one@example.com', 'list' => 'a_news'])
        ->assertRedirect();

    app('brand-context')->forget();

    $this->post('/!/marketing/subscribe', ['email' => 'two@example.com', 'list' => 'b_news'])
        ->assertRedirect();

    $rows = BrandContext::withoutBrandScope(
        fn () => Subscription::query()->orderBy('email')->get()->pluck('brand_id', 'email')->all()
    );

    expect($rows)->toBe([
        'one@example.com' => $this->brandA->id,
        'two@example.com' => $this->brandB->id,
    ]);

    // Neither brand gained the other's subscriber.
    BrandContext::setCurrent($this->brandA);
    expect(Subscription::query()->pluck('email')->all())->toBe(['one@example.com']);

    BrandContext::setCurrent($this->brandB);
    expect(Subscription::query()->pluck('email')->all())->toBe(['two@example.com']);
});

it('leaves an unknown list handle as a 404 with no brand set', function (): void {
    $this->post('/!/marketing/subscribe', [
        'email' => 'nobody@example.com',
        'list' => 'does_not_exist',
    ])->assertNotFound();

    // No record, no brand — the request must not inherit whatever brand the
    // process happened to be holding. In a long-lived worker that is how one
    // visitor's sign-up ends up in the previous visitor's brand.
    expect(app('brand-context')->hasCurrent())->toBeFalse();

    expect(BrandContext::withoutBrandScope(fn () => Subscription::query()->count()))->toBe(0);
});

it('does not inherit the brand of the request before it', function (): void {
    $this->post('/!/marketing/subscribe', ['email' => 'one@example.com', 'list' => 'a_news'])
        ->assertRedirect();

    expect(app('brand-context')->hasCurrent())->toBeTrue();

    // Same process, next visitor, unknown list: the previously resolved brand
    // must be dropped before the controller runs.
    $this->post('/!/marketing/subscribe', ['email' => 'two@example.com', 'list' => 'nope'])
        ->assertNotFound();

    expect(app('brand-context')->hasCurrent())->toBeFalse();
});
