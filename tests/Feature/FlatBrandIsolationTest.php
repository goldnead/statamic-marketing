<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;

/**
 * Two brands on the flat driver, seen through the repositories the whole addon
 * actually uses.
 *
 * The store's own test proves the files land in the right directory. This one
 * proves the layer above it never widens that again: the control panel, the
 * campaign sender and the subscribe endpoint all go through these three
 * repositories, and each of them must be as blind to the other brand as the
 * eloquent driver's global scope is.
 */
beforeEach(function (): void {
    config()->set('marketing.storage.driver', 'flat');
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $this->lists = fn () => app(MailingListRepository::class);
    $this->campaigns = fn () => app(CampaignRepository::class);
    $this->templates = fn () => app(EmailTemplateRepository::class);
});

it('gives each brand its own lists and shows neither to the other', function (): void {
    BrandContext::runFor($this->brandA, fn () => ($this->lists)()->save(
        new MailingList(handle: 'a_news', name: 'A News')
    ));

    BrandContext::runFor($this->brandB, fn () => ($this->lists)()->save(
        new MailingList(handle: 'b_news', name: 'B News')
    ));

    BrandContext::setCurrent($this->brandA);
    expect(($this->lists)()->all()->pluck('handle')->all())->toBe(['a_news'])
        ->and(($this->lists)()->find('b_news'))->toBeNull();

    BrandContext::setCurrent($this->brandB);
    expect(($this->lists)()->all()->pluck('handle')->all())->toBe(['b_news'])
        ->and(($this->lists)()->find('a_news'))->toBeNull();
});

it('gives each brand its own campaigns and templates', function (): void {
    BrandContext::runFor($this->brandA, function (): void {
        ($this->campaigns)()->save(new Campaign(handle: 'a_blast', name: 'A Blast'));
        ($this->templates)()->save(new EmailTemplate(handle: 'a_layout', name: 'A Layout'));
    });

    BrandContext::runFor($this->brandB, function (): void {
        ($this->campaigns)()->save(new Campaign(handle: 'b_blast', name: 'B Blast'));
        ($this->templates)()->save(new EmailTemplate(handle: 'b_layout', name: 'B Layout'));
    });

    BrandContext::setCurrent($this->brandA);
    expect(($this->campaigns)()->all()->pluck('handle')->all())->toBe(['a_blast'])
        ->and(($this->templates)()->all()->pluck('handle')->all())->toBe(['a_layout'])
        ->and(($this->campaigns)()->find('b_blast'))->toBeNull()
        ->and(($this->templates)()->find('b_layout'))->toBeNull();

    BrandContext::setCurrent($this->brandB);
    expect(($this->campaigns)()->find('a_blast'))->toBeNull()
        ->and(($this->templates)()->find('a_layout'))->toBeNull();
});

it('does not let one brand delete another brand\'s definition', function (): void {
    BrandContext::runFor($this->brandA, fn () => ($this->lists)()->save(
        new MailingList(handle: 'a_news', name: 'A News')
    ));

    BrandContext::setCurrent($this->brandB);
    expect(($this->lists)()->delete('a_news'))->toBeFalse();

    BrandContext::setCurrent($this->brandA);
    expect(($this->lists)()->find('a_news'))->not->toBeNull();
});

it('leaves a due campaign of one brand out of the other brand\'s due list', function (): void {
    BrandContext::runFor($this->brandA, fn () => ($this->campaigns)()->save(new Campaign(
        handle: 'a_due',
        name: 'A Due',
        status: Campaign::STATUS_SCHEDULED,
        scheduledAt: now()->subMinute()->toImmutable(),
    )));

    BrandContext::setCurrent($this->brandB);
    expect(($this->campaigns)()->due(now()))->toHaveCount(0);

    BrandContext::setCurrent($this->brandA);
    expect(($this->campaigns)()->due(now())->pluck('handle')->all())->toBe(['a_due']);
});
