<?php

namespace Goldnead\Marketing\Tests;

/**
 * The suite, with the archive left switched off — the way the addon ships.
 *
 * {@see TestCase::defineEnvironment()} turns the archive on for everything
 * else, so the archive can be tested at all. The cost of that line is that no
 * test in the suite has ever met the shipped configuration, and the campaign
 * screen built `route('marketing.archive.show')` unconditionally: on a default
 * install the route is not registered and the screen answered 500.
 *
 * Flipping the config inside a test is not enough. Routes are registered while
 * the application boots, so by the time a test body runs, `Route::has()` has
 * already been decided. The switch has to be thrown before boot, which is what
 * this class is for.
 */
abstract class ArchiveOffTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('marketing.archive.enabled', false);
    }
}
