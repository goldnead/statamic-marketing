<?php

/**
 * The tag surface, and why it is checked this way.
 *
 * `AddonServiceProvider::bootTags()` merges the provider's `$tags` property
 * with an autoload of `src/Tags/`, so the property is redundant and was
 * removed. What cannot be asserted here is the *result*: `bootTags()` runs
 * inside a `Statamic::booted()` callback and returns early unless
 * `$this->getAddon()` resolves, which needs an addon manifest built from an
 * installed Composer package. Testbench has neither, so `{{ marketing:subscribe }}`
 * renders as empty output in this suite whether the property is there or not —
 * verified by trying both. A test asserting the rendered form would therefore
 * prove nothing about the property and would fail for an unrelated reason.
 *
 * What is checkable, and is what actually matters once the list is gone, is
 * the shape core autoloads from: a class in `src/Tags/` must be a Statamic tag
 * with a handle, or it is silently not a tag at all.
 */

use Goldnead\Marketing\ServiceProvider;
use Goldnead\Marketing\Tags\Marketing;
use Statamic\Tags\Tags;

/** @return list<class-string> every class core will pick up from src/Tags/ */
function marketingTagClasses(): array
{
    $classes = [];

    foreach (glob(__DIR__.'/../../src/Tags/*.php') ?: [] as $file) {
        $classes[] = 'Goldnead\\Marketing\\Tags\\'.basename($file, '.php');
    }

    return $classes;
}

it('has every file in src/Tags/ extend the class core autoloads', function (): void {
    expect(marketingTagClasses())->not->toBeEmpty();

    foreach (marketingTagClasses() as $class) {
        expect(class_exists($class))->toBeTrue("{$class} does not exist")
            ->and(is_subclass_of($class, Tags::class))->toBeTrue("{$class} does not extend ".Tags::class);
    }
});

it('does not duplicate the autoloaded list in a $tags property', function (): void {
    // The property is not wrong, it is redundant — and a redundant list is one
    // that can disagree with the directory. If it comes back, it comes back
    // with a reason recorded here.
    $property = (new ReflectionClass(ServiceProvider::class))
        ->getDefaultProperties()['tags'] ?? [];

    expect($property)->toBe([]);
});

it('still exposes both documented tag methods', function (): void {
    // The public API the README promises: {{ marketing:subscribe }} and
    // {{ marketing:subscribe_url }}. Both are semver-locked from 1.0.
    expect(method_exists(Marketing::class, 'subscribe'))->toBeTrue()
        ->and(method_exists(Marketing::class, 'subscribeUrl'))->toBeTrue();
});
