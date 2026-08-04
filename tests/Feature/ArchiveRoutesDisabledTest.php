<?php

namespace Goldnead\Marketing\Tests\Feature;

use Goldnead\Marketing\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * The archive claims a readable path — `newsletter` by default — and a route on
 * that path wins against a host route of the same name. Checking the enabled
 * flag inside the controller is therefore not enough: the route still exists,
 * still matches first, and the host's own page becomes unreachable.
 *
 * That is not hypothetical. adriangoldner.com has its own /newsletter page, and
 * upgrading this addon to 1.10.0 stopped it rendering: two of the site's Inertia
 * smoke tests began reporting "Not a valid Inertia response" for that exact
 * path. The addon had taken a public URL from its host, during a `composer
 * update`, without being asked.
 *
 * These tests pin the fix: with the archive off, none of its routes are
 * registered at all.
 */
class ArchiveRoutesDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Deliberately the opposite of the suite default, which turns the
        // archive on so it can be tested elsewhere.
        $app['config']->set('marketing.archive.enabled', false);
    }

    public function test_no_archive_route_is_registered_when_the_archive_is_off(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values();

        $this->assertEmpty(
            $names->filter(fn ($name) => str_starts_with($name, 'marketing.archive.'))->all(),
            'The archive registered routes while switched off.'
        );
    }

    public function test_the_archive_prefix_is_left_free_for_the_host_application(): void
    {
        $prefix = config('marketing.archive.prefix', 'newsletter');

        $claimed = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => $uri === $prefix || str_starts_with($uri, $prefix.'/'))
            ->values();

        $this->assertEmpty(
            $claimed->all(),
            "The addon claimed '{$prefix}' while the archive was switched off; a host page on that path would be shadowed."
        );
    }
}
