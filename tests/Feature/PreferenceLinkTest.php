<?php

/**
 * Where the footer link goes, with and without the preference centre.
 *
 * The defect this holds shut: marketing used to serve its own preference page
 * and link to it unconditionally, so installing `goldnead/statamic-preference-
 * center` — a package whose entire purpose is that page — changed nothing a
 * reader could see. The link still landed on the single-list page.
 *
 * The preference centre is not installed in this suite and is not going to be:
 * it is optional, and a test that needs it present cannot run on a clean
 * checkout. So the "installed" branch is simulated the way it actually
 * presents itself — the facade class exists, and the token route is in the
 * registry — which is exactly the pair {@see PreferenceLink::centerAvailable()}
 * asks about. The point of the test is that the resolver reads both and
 * switches on them, and that nothing else in the addon decides this on its own.
 */

use Goldnead\Marketing\Support\PreferenceLink;

// `marketingFakePreferenceCenterClass()` and `marketingFakePreferenceCenter
// Route()` are in tests/Pest.php: the renderer's link test needs the same
// simulation, and a helper declared at file scope twice is a fatal error.

it('sends a person to marketing\'s own unsubscribe page when no centre is installed', function (): void {
    $link = app(PreferenceLink::class);

    expect($link->centerAvailable())->toBeFalse()
        ->and($link->manage('tok-1'))->toBe(route('marketing.unsubscribe', ['token' => 'tok-1']));
});

it('sends a person to the preference centre once it is installed', function (): void {
    marketingFakePreferenceCenterClass();
    marketingFakePreferenceCenterRoute();

    $link = app(PreferenceLink::class);

    expect($link->centerAvailable())->toBeTrue()
        ->and($link->manage('tok-1'))->toContain('/!/preference-center/t/tok-1');
});

it('keeps the one-click endpoint on marketing in both cases', function (): void {
    // RFC 8058: a mail provider POSTs this with no session and expects the
    // unsubscribe to have happened, not a page. It may never move to an
    // optional package, and it may never become a preference form.
    $link = app(PreferenceLink::class);

    $without = $link->oneClick('tok-1');

    marketingFakePreferenceCenterClass();
    marketingFakePreferenceCenterRoute();

    expect($link->oneClick('tok-1'))->toBe($without)
        ->and($without)->toBe(route('marketing.unsubscribe', ['token' => 'tok-1']));
});

it('does not fall over when the class is there but the route is not', function (): void {
    // The centre registers its token route only where marketing is installed,
    // so class-present-route-absent is a state that really occurs. `route()`
    // on an unregistered name throws, which is why the registry is asked.
    marketingFakePreferenceCenterClass();

    $link = app(PreferenceLink::class);

    expect($link->centerAvailable())->toBeFalse()
        ->and($link->manage('tok-1'))->toBe($link->oneClick('tok-1'));
});

it('probes the facade by existence and registry, never by method_exists', function (): void {
    // automations 1.0.3 disabled every LeadHub action node on real installs by
    // calling method_exists() on a facade class. Facades answer through
    // __callStatic, so that is false for every proxied method. The rule is
    // cheap to state and expensive to relearn, so it is stated here.
    $source = file_get_contents(__DIR__.'/../../src/Support/PreferenceLink.php');

    expect($source)->not->toMatch('/method_exists\s*\(\s*(self::CENTER_FACADE|[\'"\\\\]*Goldnead\\\\+PreferenceCenter)/');
});

it('leaves no call site generating a subscriber link on its own', function (): void {
    // One resolver means one resolver. Any src/ file other than PreferenceLink
    // that builds the unsubscribe route by hand has re-created the bug.
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_ends_with(str_replace('\\', '/', $file->getPathname()), 'src/Support/PreferenceLink.php')) {
            continue;
        }

        if (preg_match('/route\(\s*[\'"]marketing\.(unsubscribe|preferences)/', (string) file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
