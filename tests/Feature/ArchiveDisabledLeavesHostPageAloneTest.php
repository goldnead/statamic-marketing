<?php

namespace Goldnead\Marketing\Tests\Feature;

use Goldnead\Marketing\Tests\TestCase;

/**
 * The same fix from the host's side: a site with its own page on that path
 * keeps it. Separate class because the host route has to be registered through
 * the framework hook, before the addon's routes are in place — and that hook
 * applies to a whole class, which would make the "prefix is free" assertion
 * above fail against this test's own route.
 */
class ArchiveDisabledLeavesHostPageAloneTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('marketing.archive.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        $router->get('newsletter', fn () => 'the host page')->name('host.newsletter');
    }

    public function test_the_host_keeps_its_own_page_on_that_path(): void
    {
        $this->get('/newsletter')
            ->assertOk()
            ->assertSee('the host page');
    }
}
