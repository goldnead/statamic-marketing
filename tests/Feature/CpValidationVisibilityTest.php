<?php

/**
 * Structural guard for the one failure this control panel kept repeating: the
 * server rejects an input, nothing is written, and the screen does not change —
 * Save looks like a dead button.
 *
 * There is no JS test runner in this addon (deliberately: introducing one was
 * not part of this fix), so the Vue side is asserted structurally here. These
 * tests fail the moment somebody adds a form that submits without handling the
 * rejection, or validates a field that has nowhere to show its error.
 */

use Symfony\Component\Finder\Finder;

/** Absolute path to the CP page components. */
function marketingPagesPath(): string
{
    return dirname(__DIR__, 2).'/resources/js/pages';
}

/** Every .vue page keyed by its path relative to resources/js/pages. */
function marketingPages(): array
{
    $pages = [];

    foreach (Finder::create()->files()->in(marketingPagesPath())->name('*.vue') as $file) {
        $pages[$file->getRelativePathname()] = $file->getContents();
    }

    ksort($pages);

    return $pages;
}

/**
 * Top-level `function name() { … }` bodies of a `<script setup>` block, keyed by
 * name. Brace-matched rather than regexed, so nested objects and closures stay
 * inside the body they belong to.
 */
function marketingFunctionBodies(string $source): array
{
    $bodies = [];
    $offset = 0;

    while (preg_match('/\bfunction\s+(\w+)\s*\([^)]*\)\s*\{/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $name = $m[1][0];
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $i = $start;

        while ($i < strlen($source) && $depth > 0) {
            $char = $source[$i];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }

            $i++;
        }

        $bodies[$name] = substr($source, $start, $i - $start - 1);
        $offset = $i;
    }

    return $bodies;
}

/** Does this block send something the server can reject? */
function marketingSubmits(string $block): bool
{
    return (bool) preg_match('/router\.(post|patch|put|delete)\s*\(/', $block);
}

test('every submitting page handles the rejection it can receive', function (): void {
    $missing = [];

    foreach (marketingPages() as $page => $source) {
        foreach (marketingFunctionBodies($source) as $name => $body) {
            if (marketingSubmits($body) && ! str_contains($body, 'onError')) {
                $missing[] = "{$page}::{$name}()";
            }
        }
    }

    expect($missing)->toBe([], 'These submit to the server but ignore a rejected response, so the failure is invisible: '.implode(', ', $missing));
});

test('every submitting page renders a summary for errors that belong to no field', function (): void {
    $missing = [];

    foreach (marketingPages() as $page => $source) {
        if (! marketingSubmits($source)) {
            continue;
        }

        if (! str_contains($source, 'data-marketing-form-errors')) {
            $missing[] = $page;
        }
    }

    expect($missing)->toBe([], 'These submit but have no place to show an error that maps to no field (a refused send, a refused delete): '.implode(', ', $missing));
});

test('every key a page claims to render at its field is actually rendered there', function (): void {
    $missing = [];

    foreach (marketingPages() as $page => $source) {
        if (! preg_match('/const fieldKeys = \[(.*?)\];/s', $source, $m)) {
            continue;
        }

        preg_match_all("/'([a-z_]+)'/", $m[1], $keys);

        foreach ($keys[1] as $key) {
            // A key listed here is filtered out of the summary. If it is not
            // bound to a field as well, the error disappears entirely.
            if (! str_contains($source, "formErrors.{$key}")) {
                $missing[] = "{$page}: {$key}";
            }
        }
    }

    expect($missing)->toBe([], 'Listed as handled at the field but never bound to one, so the error is filtered out of the summary and shown nowhere: '.implode(', ', $missing));
});

test('every field the CP validates has somewhere to show its error', function (): void {
    // Keys the server reports that belong to no input: a refused send, a
    // campaign that is no longer editable, a sequence whose automation could
    // not be written. They are covered by the summary.
    $generalKeys = ['send', 'status', 'automation'];

    $validated = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/src/Http/Controllers/Cp')->name('*.php') as $file) {
        preg_match_all('/validate\(\[(.*?)\n\s*\]\)/s', $file->getContents(), $blocks);

        foreach ($blocks[1] as $block) {
            preg_match_all("/'([a-z_]+)'\s*=>\s*\[/", $block, $keys);
            $validated = array_merge($validated, $keys[1]);
        }

        preg_match_all("/withErrors\(\['([a-z_]+)'/", $file->getContents(), $flashed);
        $validated = array_merge($validated, $flashed[1]);
    }

    $validated = array_values(array_unique(array_diff($validated, $generalKeys)));

    expect($validated)->not->toBeEmpty();

    $rendered = implode("\n", marketingPages());

    $unrendered = array_values(array_filter(
        $validated,
        fn (string $key): bool => ! str_contains($rendered, "formErrors.{$key}")
    ));

    expect($unrendered)->toBe([], 'The CP rejects these fields but no page renders their error: '.implode(', ', $unrendered));
});
