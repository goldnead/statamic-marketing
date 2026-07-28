<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\MailingListRecord;
use Statamic\Facades\User;

/**
 * One list handle, one brand — enforced, not merely assumed.
 *
 * The public subscribe endpoint derives the brand from the list handle the
 * form names. That derivation is only sound while a handle has exactly one
 * owner, and brand-context is explicit that a value which is unique only *per
 * brand* must never be used this way: two owners and it throws, which means
 * every sign-up for that handle stops working in both brands at once.
 *
 * The brand-scoping migration had made the list handle unique per brand, so
 * nothing stopped a second brand from claiming `newsletter`. Both drivers now
 * refuse it, and the control panel says so at the field instead of dying.
 */
beforeEach(function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $this->user = User::make()->email('cp@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

afterEach(function (): void {
    BrandContext::withoutBrandScope(fn () => MailingListRecord::query()->delete());
});

it('holds the list handle unique across brands in the database', function (): void {
    config()->set('marketing.storage.driver', 'eloquent');

    BrandContext::runFor($this->brandA, fn () => MailingListRecord::create([
        'handle' => 'newsletter',
        'name' => 'A Newsletter',
    ]));

    expect(fn () => BrandContext::runFor($this->brandB, fn () => MailingListRecord::create([
        'handle' => 'newsletter',
        'name' => 'B Newsletter',
    ])))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('tells an editor which brand already holds the handle instead of crashing', function (string $driver): void {
    config()->set('marketing.storage.driver', $driver);

    BrandContext::runFor($this->brandA, fn () => app(MailingListRepository::class)->save(
        new MailingList(handle: 'newsletter', name: 'A Newsletter')
    ));

    BrandContext::setCurrent($this->brandB);

    $this->post(cp_route('marketing.lists.store'), [
        'name' => 'B Newsletter',
        'handle' => 'newsletter',
    ])->assertSessionHasErrors('handle');

    // Nothing was written for brand B — the refusal is the whole point.
    BrandContext::setCurrent($this->brandB);
    expect(app(MailingListRepository::class)->find('newsletter'))->toBeNull();

    // And brand A still has exactly what it had.
    BrandContext::setCurrent($this->brandA);
    expect(app(MailingListRepository::class)->find('newsletter')->name)->toBe('A Newsletter');
})->with(['flat', 'eloquent']);

it('still lets a brand reuse its own handle check unchanged', function (): void {
    config()->set('marketing.storage.driver', 'flat');

    BrandContext::setCurrent($this->brandB);

    $this->post(cp_route('marketing.lists.store'), [
        'name' => 'Only Mine',
        'handle' => 'mine',
    ])->assertSessionHasNoErrors();

    expect(app(MailingListRepository::class)->find('mine'))->not->toBeNull();
});
