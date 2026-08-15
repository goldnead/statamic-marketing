<?php

/**
 * Every string the Control Panel shows has somewhere to be translated.
 *
 * The Vue layer calls `__()` with the English sentence as the key — the
 * ordinary Statamic idiom — and those keys resolve through the JSON loader,
 * not through the `marketing::` namespace the PHP lang files serve. Until
 * `resources/lang/de.json` existed there was no JSON file and no registered
 * JSON path, so 76 of them had no translation at all: the nav and the flashes
 * came out German from the PHP files and the entire screen behind them stayed
 * English. That is worse than shipping no German, and it is the kind of gap
 * nothing fails on — which is why it is asserted rather than remembered.
 *
 * The test reads the sources rather than a list, so a new `__('…')` in a
 * template fails here on the day it is written instead of on the day somebody
 * switches a Control Panel to German.
 */

use Illuminate\Support\Arr;

/** Every bare (non-namespaced) key the Vue layer asks the translator for. */
function marketingCpStrings(): array
{
    $keys = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../resources/js', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['vue', 'js'], true)) {
            continue;
        }

        preg_match_all(
            '/__\(\s*([\'"])(.*?)\1/s',
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        foreach ($matches[2] as $key) {
            if (! str_contains($key, '::')) {
                $keys[$key] = true;
            }
        }
    }

    $keys = array_keys($keys);
    sort($keys);

    return $keys;
}

/**
 * Every `marketing::`-namespaced key the Vue layer asks the translator for.
 *
 * The other kind of string on these screens, and the one the charts use
 * throughout. A namespaced key is safer than a bare one in the way that
 * matters between siblings: bare keys from every addon are merged into one
 * flat dictionary for the whole Control Panel, so two addons that both
 * translate "Opens" are one addon overwriting the other's word. A namespaced
 * key cannot collide with anything.
 *
 * What it can do instead is silently render as itself. `getLine()` falls back
 * to the key when nothing resolves, so a typo or a lang file that was only
 * half-written puts `marketing::dashboard.growth_heading` on the screen — in
 * both languages, without an error anywhere.
 *
 * The scan reads the whole call, not the first thing inside the bracket. The
 * old pattern required a quote immediately after `__(`, and the four axis
 * labels of the activity curve are picked by a ternary —
 * `__(unit === 'day' ? 'a' : 'b')` — so they were never scanned at all. That is
 * the worst place to be blind: a branch chosen at runtime is a key nobody looks
 * at until the day the data takes the other road.
 *
 * The literal alone is not enough either, because `marketing::` is also the
 * Inertia page namespace (`marketing::Campaigns/Show` in cp.js), and those are
 * component names with nothing to translate. So: find the `__()` calls with
 * their brackets balanced, and take every `marketing::` string inside one.
 *
 * @return list<string>
 */
function marketingNamespacedCpStrings(): array
{
    $keys = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../resources/js', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['vue', 'js'], true)) {
            continue;
        }

        // `(?1)` recurses group 1, so the argument list is matched to its own
        // closing bracket however deep the calls inside it nest — a key handed
        // `{ max: Math.max(1, ...xs.map((n) => n)) }` is three levels down.
        preg_match_all(
            '/__(\((?:[^()]++|(?1))*\))/s',
            (string) file_get_contents($file->getPathname()),
            $calls,
        );

        foreach ($calls[1] as $call) {
            preg_match_all('/([\'"])marketing::(.*?)\1/s', $call, $matches);

            foreach ($matches[2] as $key) {
                $keys[$key] = true;
            }
        }
    }

    $keys = array_keys($keys);
    sort($keys);

    return $keys;
}

function marketingJsonLang(string $locale): array
{
    return json_decode(
        (string) file_get_contents(__DIR__."/../../resources/lang/{$locale}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

it('has a German entry for every bare string the CP renders', function (): void {
    $missing = array_values(array_diff(marketingCpStrings(), array_keys(marketingJsonLang('de'))));

    expect($missing)->toBe([]);
});

it('carries no German entry for a string the CP no longer renders', function (): void {
    // A stale entry is not harmless: it is a translation somebody maintains for
    // a screen that is gone, and it hides the fact that the real string moved.
    $stale = array_values(array_diff(array_keys(marketingJsonLang('de')), marketingCpStrings()));

    expect($stale)->toBe([]);
});

it('keeps en.json and de.json at exactly the same keys', function (): void {
    // en.json is the identity map. It exists so the set is inspectable in one
    // place and so a diff shows what changed, not what was translated.
    expect(array_keys(marketingJsonLang('en')))->toBe(array_keys(marketingJsonLang('de')));
});

it('actually resolves a CP string through the translator in German', function (): void {
    // The file alone proves nothing — the JSON path has to be registered on
    // the translator, which is a separate line in the service provider and was
    // the half that was missing.
    app()->setLocale('de');

    expect(__('Send now'))->toBe('Jetzt senden')
        ->and(__('Create campaign'))->toBe('Kampagne anlegen');
});

it('leaves an English CP untouched', function (): void {
    app()->setLocale('en');

    expect(__('Send now'))->toBe('Send now');
});

it('resolves every namespaced key the CP renders, in both languages', function (string $locale): void {
    app()->setLocale($locale);

    // The scan itself first: a regex that matched nothing would make the
    // assertion below true by having no keys to be wrong about.
    expect(marketingNamespacedCpStrings())->not->toBeEmpty();

    $unresolved = array_values(array_filter(
        marketingNamespacedCpStrings(),
        fn (string $key): bool => __("marketing::{$key}") === "marketing::{$key}",
    ));

    expect($unresolved)->toBe([], 'These keys render as their own name on a Control Panel screen.');
})->with(['de', 'en']);

it('sees the keys that are chosen inside the call rather than written at its front', function (): void {
    // The four axis labels of the activity curve, each picked by a ternary. The
    // guard above is only worth what its scan reaches, and this is the shape it
    // used to walk straight past.
    expect(marketingNamespacedCpStrings())->toContain(
        'campaigns.report.activity_hourly',
        'campaigns.report.activity_daily',
        'campaigns.report.activity_scale_hourly',
        'campaigns.report.activity_scale_daily',
    );

    // And it has not started reading Inertia page names as translatable
    // strings on the way — `marketing::` is that namespace too.
    expect(marketingNamespacedCpStrings())->not->toContain('Campaigns/Show');
});

it('keeps the namespaced files at the same keys in both languages', function (): void {
    // Not a formality: the JSON pair is asserted key-for-key above and the PHP
    // files were not, so a string added to `en/dashboard.php` and forgotten in
    // `de/dashboard.php` fell back to English on a German screen — next to
    // German — and nothing said so.
    $flatten = function (string $locale): array {
        $keys = [];

        foreach (glob(__DIR__."/../../resources/lang/{$locale}/*.php") ?: [] as $file) {
            $keys[] = array_keys(Arr::dot([basename($file, '.php') => require $file]));
        }

        $keys = array_merge(...$keys ?: [[]]);
        sort($keys);

        return $keys;
    };

    expect($flatten('de'))->toBe($flatten('en'));
});
