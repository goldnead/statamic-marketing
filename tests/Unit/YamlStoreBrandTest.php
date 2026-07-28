<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Exceptions\HandleNotUniqueAcrossBrands;
use Goldnead\Marketing\Repositories\FlatFile\YamlStore;

/**
 * The flat store under multi-brand.
 *
 * Before 1.6 this store had no notion of a brand at all: one directory per
 * type, every definition visible to everybody. What it grows here is a
 * security boundary, so the cases that matter are the negative ones — what a
 * brand must NOT be able to see, and what it must not be able to write.
 */
beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/marketing-yamlstore-brand-'.uniqid();
    $this->store = new YamlStore($this->path);
});

afterEach(function (): void {
    $delete = function (string $dir) use (&$delete): void {
        foreach (glob($dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? $delete($entry) : unlink($entry);
        }
        @rmdir($dir);
    };

    $delete($this->path);
});

/** Turn multi-brand on and hand back two brands next to the seeded default. */
function yamlStoreBrands(): array
{
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    return [
        Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']),
        Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']),
    ];
}

it('keeps a single-brand install on the pre-1.6 layout, with no directory to move', function (): void {
    // No multi-brand: the file has to land exactly where 1.5 put it, because
    // an install that updates and does nothing must stay working.
    $this->store->write('lists', 'newsletter', ['name' => 'Newsletter']);

    expect(is_file($this->path.'/lists/newsletter.yaml'))->toBeTrue()
        ->and(glob($this->path.'/*/lists') ?: [])->toBe([])
        ->and($this->store->read('lists', 'newsletter')['name'])->toBe('Newsletter')
        ->and($this->store->all('lists'))->toHaveCount(1);
});

it('files a definition under its brand and hides it from the other brand', function (): void {
    [$a, $b] = yamlStoreBrands();

    BrandContext::runFor($a, fn () => $this->store->write('lists', 'a-list', ['name' => 'A']));
    BrandContext::runFor($b, fn () => $this->store->write('lists', 'b-list', ['name' => 'B']));

    expect(is_file($this->path.'/brand-a/lists/a-list.yaml'))->toBeTrue()
        ->and(is_file($this->path.'/brand-b/lists/b-list.yaml'))->toBeTrue();

    BrandContext::setCurrent($a);
    expect($this->store->all('lists')->pluck('handle')->all())->toBe(['a-list'])
        ->and($this->store->read('lists', 'b-list'))->toBeNull();

    BrandContext::setCurrent($b);
    expect($this->store->all('lists')->pluck('handle')->all())->toBe(['b-list'])
        ->and($this->store->read('lists', 'a-list'))->toBeNull();
});

it('isolates campaigns and templates the same way, not just lists', function (): void {
    [$a, $b] = yamlStoreBrands();

    BrandContext::runFor($a, function (): void {
        $this->store->write('campaigns', 'a-campaign', ['name' => 'A']);
        $this->store->write('templates', 'a-template', ['name' => 'A']);
    });

    BrandContext::setCurrent($b);

    expect($this->store->all('campaigns'))->toHaveCount(0)
        ->and($this->store->all('templates'))->toHaveCount(0)
        ->and($this->store->read('campaigns', 'a-campaign'))->toBeNull()
        ->and($this->store->read('templates', 'a-template'))->toBeNull();
});

it('lets the default brand read pre-1.6 files, and no other brand', function (): void {
    // Written the way 1.5 wrote them: no brand anywhere.
    mkdir($this->path.'/lists', 0755, true);
    file_put_contents($this->path.'/lists/legacy.yaml', "name: Legacy\n");

    [, $b] = yamlStoreBrands();

    BrandContext::setCurrent(Brand::default());
    expect($this->store->all('lists')->pluck('handle')->all())->toBe(['legacy'])
        ->and($this->store->read('lists', 'legacy')['name'])->toBe('Legacy');

    BrandContext::setCurrent($b);
    expect($this->store->all('lists'))->toHaveCount(0)
        ->and($this->store->read('lists', 'legacy'))->toBeNull();
});

it('edits a pre-1.6 file where it lies instead of leaving a second copy behind', function (): void {
    mkdir($this->path.'/lists', 0755, true);
    file_put_contents($this->path.'/lists/legacy.yaml', "name: Legacy\n");

    yamlStoreBrands();
    BrandContext::setCurrent(Brand::default());

    $this->store->write('lists', 'legacy', ['name' => 'Renamed']);

    expect(is_file($this->path.'/default/lists/legacy.yaml'))->toBeFalse()
        ->and(is_file($this->path.'/lists/legacy.yaml'))->toBeTrue()
        ->and($this->store->read('lists', 'legacy')['name'])->toBe('Renamed')
        ->and($this->store->all('lists'))->toHaveCount(1);
});

it('refuses to give a second brand a handle another brand already holds', function (): void {
    [$a, $b] = yamlStoreBrands();

    BrandContext::runFor($a, fn () => $this->store->write('lists', 'newsletter', ['name' => 'A']));

    // The whole public-route derivation rests on one handle having one owner.
    BrandContext::setCurrent($b);
    expect(fn () => $this->store->write('lists', 'newsletter', ['name' => 'B']))
        ->toThrow(HandleNotUniqueAcrossBrands::class);

    expect(is_file($this->path.'/brand-b/lists/newsletter.yaml'))->toBeFalse();
});

it('refuses a handle that a pre-1.6 file still holds for the default brand', function (): void {
    mkdir($this->path.'/lists', 0755, true);
    file_put_contents($this->path.'/lists/newsletter.yaml', "name: Legacy\n");

    [, $b] = yamlStoreBrands();
    BrandContext::setCurrent($b);

    expect(fn () => $this->store->write('lists', 'newsletter', ['name' => 'B']))
        ->toThrow(HandleNotUniqueAcrossBrands::class);
});

it('names every brand holding a handle, ignoring whichever one is current', function (): void {
    [$a] = yamlStoreBrands();

    BrandContext::runFor($a, fn () => $this->store->write('lists', 'a-list', ['name' => 'A']));
    mkdir($this->path.'/lists', 0755, true);
    file_put_contents($this->path.'/lists/legacy.yaml', "name: Legacy\n");

    BrandContext::forget();

    expect($this->store->brandsWithHandle('lists', 'a-list'))->toBe(['brand-a'])
        ->and($this->store->brandsWithHandle('lists', 'legacy'))->toBe(['default'])
        ->and($this->store->brandsWithHandle('lists', 'nothing'))->toBe([]);
});

it('shows nothing when multi-brand is on and no brand has been resolved', function (): void {
    // The eloquent driver's global scope fails closed here. Handing back the
    // default brand's definitions instead would give an unresolved request
    // data it never proved it may see.
    [$a] = yamlStoreBrands();

    BrandContext::runFor($a, fn () => $this->store->write('lists', 'a-list', ['name' => 'A']));

    BrandContext::forget();

    expect($this->store->all('lists'))->toHaveCount(0)
        ->and($this->store->read('lists', 'a-list'))->toBeNull();
});

it('does not treat a type directory as a brand', function (): void {
    // content/marketing/lists is a type directory, not a brand called "lists".
    mkdir($this->path.'/lists', 0755, true);
    file_put_contents($this->path.'/lists/legacy.yaml', "name: Legacy\n");

    expect($this->store->segments())->toBe(['']);
});
