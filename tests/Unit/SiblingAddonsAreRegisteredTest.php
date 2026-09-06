<?php

use Goldnead\Marketing\Tests\TestCase;
use Statamic\Facades\Addon;

/**
 * The test bed's own precondition, stated where it can fail with its own name.
 *
 * Marketing's report links a recipient to their LeadHub contact, so the suite
 * needs `statamic.cp.leadhub.contacts.show` to exist. That route exists only if
 * LeadHub is an entry in Statamic's addon manifest — registering its
 * ServiceProvider is not enough, because `AddonServiceProvider::boot()` looks
 * the provider up in the manifest first and does nothing when it is not there.
 *
 * Without this, an empty manifest surfaces three directories away as
 * `RouteNotFoundException` inside a campaign report — a symptom that says
 * nothing about its cause, and cost a full CI-versus-local investigation once
 * already. Here it says what actually broke.
 *
 * @see TestCase::buildAddonManifest()
 */
it('knows the required siblings as addons, not merely as service providers', function (string $package): void {
    expect(Addon::all()->map->id()->all())->toContain($package);
})->with([
    'goldnead/statamic-leadhub',
    'goldnead/statamic-brand-context',
    'goldnead/statamic-suppression',
]);

it('has the LeadHub contact route the campaign report links to', function (): void {
    expect(Route::has('statamic.cp.leadhub.contacts.show'))->toBeTrue();
});
