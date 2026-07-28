<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Repositories\FlatFile\YamlStore;

/**
 * `marketing:migrate-flat-brands`.
 *
 * The command exists for one situation: an install that ran on the flat driver
 * before brands existed and now has a second brand. Its files are read as the
 * default brand's either way, so nothing is broken while it waits — but once
 * two brands share a directory tree, every definition has to say whose it is.
 *
 * The bar it has to clear is that nothing may be lost: it moves, it never
 * overwrites, it never deletes, a second run is a no-op, and a dry run leaves
 * the disk exactly as it found it.
 */
beforeEach(function (): void {
    config()->set('marketing.storage.driver', 'flat');

    $this->base = (string) config('marketing.storage.flat.path');

    $this->legacy = function (string $type, string $handle, string $body): string {
        $dir = $this->base.'/'.$type;
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file = $dir.'/'.$handle.'.yaml', $body);

        return $file;
    };
});

it('shows every move in a dry run and changes nothing on disk', function (): void {
    ($this->legacy)('lists', 'newsletter', "name: Newsletter\n");
    ($this->legacy)('campaigns', 'spring', "name: Spring\n");
    ($this->legacy)('templates', 'plain', "name: Plain\n");

    $before = fingerprint($this->base);

    $this->artisan('marketing:migrate-flat-brands', ['--dry-run' => true])
        ->expectsOutputToContain('default/lists/newsletter.yaml')
        ->assertSuccessful();

    expect(fingerprint($this->base))->toBe($before);
});

it('moves lists, campaigns and templates into the default brand without losing one', function (): void {
    ($this->legacy)('lists', 'newsletter', "name: Newsletter\ndouble_opt_in: true\n");
    ($this->legacy)('lists', 'offers', "name: Offers\n");
    ($this->legacy)('campaigns', 'spring', "name: Spring\n");
    ($this->legacy)('templates', 'plain', "name: Plain\n");

    $this->artisan('marketing:migrate-flat-brands')->assertSuccessful();

    expect(is_file($this->base.'/default/lists/newsletter.yaml'))->toBeTrue()
        ->and(is_file($this->base.'/default/lists/offers.yaml'))->toBeTrue()
        ->and(is_file($this->base.'/default/campaigns/spring.yaml'))->toBeTrue()
        ->and(is_file($this->base.'/default/templates/plain.yaml'))->toBeTrue()
        ->and(glob($this->base.'/lists/*.yaml') ?: [])->toBe([])
        ->and(glob($this->base.'/campaigns/*.yaml') ?: [])->toBe([])
        ->and(glob($this->base.'/templates/*.yaml') ?: [])->toBe([]);

    // The content survived the move byte for byte, not just the filename.
    expect(file_get_contents($this->base.'/default/lists/newsletter.yaml'))
        ->toBe("name: Newsletter\ndouble_opt_in: true\n");

    // And the addon still reads them, now as the default brand's.
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    BrandContext::setCurrent(Brand::default());

    expect(app(MailingListRepository::class)->all()->pluck('handle')->all())
        ->toBe(['newsletter', 'offers']);
});

it('is a no-op the second time it runs', function (): void {
    ($this->legacy)('lists', 'newsletter', "name: Newsletter\n");

    $this->artisan('marketing:migrate-flat-brands')->assertSuccessful();

    $after = fingerprint($this->base);

    $this->artisan('marketing:migrate-flat-brands')
        ->expectsOutputToContain('Nothing to move')
        ->assertSuccessful();

    expect(fingerprint($this->base))->toBe($after);
});

it('moves into a named brand when asked', function (): void {
    Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    ($this->legacy)('lists', 'newsletter', "name: Newsletter\n");

    $this->artisan('marketing:migrate-flat-brands', ['--brand' => 'brand-b'])->assertSuccessful();

    expect(is_file($this->base.'/brand-b/lists/newsletter.yaml'))->toBeTrue()
        ->and(is_file($this->base.'/default/lists/newsletter.yaml'))->toBeFalse();
});

it('refuses an unknown brand rather than inventing a directory for it', function (): void {
    ($this->legacy)('lists', 'newsletter', "name: Newsletter\n");

    $this->artisan('marketing:migrate-flat-brands', ['--brand' => 'ghost'])->assertFailed();

    expect(is_dir($this->base.'/ghost'))->toBeFalse()
        ->and(is_file($this->base.'/lists/newsletter.yaml'))->toBeTrue();
});

it('refuses to overwrite a definition already sitting in the target', function (): void {
    ($this->legacy)('lists', 'newsletter', "name: Old\n");

    mkdir($this->base.'/default/lists', 0755, true);
    file_put_contents($this->base.'/default/lists/newsletter.yaml', "name: Already there\n");

    $this->artisan('marketing:migrate-flat-brands')
        ->expectsOutputToContain('refused')
        ->assertFailed();

    // Neither file was touched. Losing either one is the failure mode that
    // matters here, and "refused" is the only answer that loses nothing.
    expect(file_get_contents($this->base.'/default/lists/newsletter.yaml'))->toBe("name: Already there\n")
        ->and(file_get_contents($this->base.'/lists/newsletter.yaml'))->toBe("name: Old\n");
});

it('refuses to hand a brand a handle another brand already holds', function (): void {
    Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    ($this->legacy)('lists', 'newsletter', "name: Legacy\n");

    mkdir($this->base.'/default/lists', 0755, true);
    file_put_contents($this->base.'/default/lists/newsletter.yaml', "name: Default's\n");

    $this->artisan('marketing:migrate-flat-brands', ['--brand' => 'brand-b'])
        ->expectsOutputToContain('already belongs to brand [default]')
        ->assertFailed();

    expect(is_file($this->base.'/brand-b/lists/newsletter.yaml'))->toBeFalse();
});

it('does nothing at all on the eloquent driver', function (): void {
    config()->set('marketing.storage.driver', 'eloquent');

    ($this->legacy)('lists', 'newsletter', "name: Newsletter\n");

    $this->artisan('marketing:migrate-flat-brands')->assertSuccessful();

    expect(is_file($this->base.'/lists/newsletter.yaml'))->toBeTrue()
        ->and(is_dir($this->base.'/default'))->toBeFalse();
});

/** Every file under a path with its contents — a move shows up, a read does not. */
function fingerprint(string $base): array
{
    $files = [];

    foreach (YamlStore::TYPES as $type) {
        foreach (glob($base.'/*/'.$type.'/*.yaml') ?: [] as $file) {
            $files[substr($file, strlen($base) + 1)] = md5_file($file);
        }
        foreach (glob($base.'/'.$type.'/*.yaml') ?: [] as $file) {
            $files[substr($file, strlen($base) + 1)] = md5_file($file);
        }
    }

    ksort($files);

    return $files;
}
