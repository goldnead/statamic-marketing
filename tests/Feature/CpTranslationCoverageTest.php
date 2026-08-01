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
