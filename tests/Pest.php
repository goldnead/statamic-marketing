<?php

use Goldnead\Marketing\Support\PreferenceLink;
use Goldnead\Marketing\Tests\ArchiveOffTestCase;
use Goldnead\Marketing\Tests\InsightsTestCase;
use Goldnead\Marketing\Tests\Integration\SiblingsTestCase;
use Goldnead\Marketing\Tests\MigrationPathTestCase;
use Goldnead\Marketing\Tests\TestCase;
use Illuminate\Support\Facades\Route;

uses(TestCase::class)->in('Feature', 'Unit');
uses(SiblingsTestCase::class)->in('Integration');

// The bridge to the analytics addon needs its stand-ins in place before the
// application boots — the sibling is a `suggest` and is not installed, so the
// contract, the base class and the facade all have to be declared by hand, and
// the facade before the provider's booted() callback looks for it. A directory
// of its own because Pest binds a test case per top-level folder and this one
// needs a different base class than the rest of the suite.
uses(InsightsTestCase::class)->in('Insights');

// The addon as it ships: TestCase switches the archive on for everything else,
// so nothing in the rest of the suite ever meets the default configuration.
uses(ArchiveOffTestCase::class)->in('ShippedDefaults');

// The migration tests drive migrations by hand, against a database of their
// own — see MigrationPathTestCase. The rest of the suite meets a database that
// RefreshDatabase has already migrated to head, which is the one shape a
// migration can never be wrong about.
uses(MigrationPathTestCase::class)->in('Migrations');

/*
 * `goldnead/statamic-preference-center`, simulated.
 *
 * The centre is optional and is not installed in this suite — a test that
 * needed it present could not run on a clean checkout. So the installed state
 * is reproduced the way it actually presents itself, which is the pair
 * {@see PreferenceLink::centerAvailable()} asks about: the facade class exists,
 * and the token route is in the registry.
 *
 * They live here rather than in one test file because two of them need the
 * same simulation — where the footer link goes (PreferenceLinkTest) and which
 * links the renderer leaves out of the click redirect (CampaignLinkRewriting
 * Test) — and a helper declared twice at file scope is a fatal redeclaration.
 */

/**
 * Stands in for the sibling's facade class.
 *
 * `class_exists()` is answered by the autoloader, and there is no way to make
 * it true for a class nobody has defined — so the class is defined here, under
 * the name the resolver names. It has no body because the resolver never calls
 * it: it asks whether it exists, which is the whole contract.
 */
function marketingFakePreferenceCenterClass(): void
{
    if (! class_exists(PreferenceLink::CENTER_FACADE)) {
        eval('namespace Goldnead\PreferenceCenter\Facades; class PreferenceCenter {}');
    }
}

/**
 * Registers the token route the preference centre would register.
 *
 * The name lookup has to be refreshed by hand. `->name()` runs after the route
 * is already in the collection, and the framework rebuilds the name list once,
 * from `booted()` — which is long past by the time a test registers anything.
 * In an installed sibling the route is declared during boot and the framework
 * does this itself.
 */
function marketingFakePreferenceCenterRoute(): void
{
    Route::get('/!/preference-center/t/{'.PreferenceLink::CENTER_PARAMETER.'}', fn () => 'centre')
        ->name(PreferenceLink::CENTER_ROUTE);

    Route::getRoutes()->refreshNameLookups();
}
