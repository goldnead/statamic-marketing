<?php

namespace Goldnead\Marketing\Tests\Integration;

use Goldnead\Marketing\Integrations\Automations\AutomationsBridge;
use Goldnead\Marketing\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Marketing\Tests\TestCase;
use Goldnead\StatamicAutomations\ServiceProvider;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;

/**
 * Base for the live sibling-addon integration suite. The siblings are
 * OPTIONAL peers: the default test run has them absent (tests self-skip);
 * scripts/test-siblings.sh installs them into a throwaway copy and runs
 * only this suite.
 */
abstract class SiblingsTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Only the migrations are loaded here. The siblings' bootAddon() is
        // deliberately NOT called: `getPackageProviders()` below hands both
        // providers to testbench, testbench boots the application, and
        // Statamic's own AppServiceProvider runs `Statamic::runBootedCallbacks()`
        // from an `app->booted()` hook — which is exactly what invokes
        // `AddonServiceProvider::bootAddon()`. Forcing it again here was a
        // second boot, not a first one.
        //
        // That cost a real assertion. `statamic-automations` wires the
        // marketing domain events to its engine in `bootAddon()` via
        // `Event::listen()`, and `Event::listen()` appends. Booted twice, one
        // subscription started two automation runs and sent the first mail of
        // a welcome series twice — which is what
        // `AutomationsIntegrationTest > it runs a two-mail sequence as an
        // ordinary node chain` reported as "2 is identical to 1". Nothing in
        // the addons was wrong: a real installation boots each addon once.
        if (class_exists(ServiceProvider::class)) {
            $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-automations/database/migrations');
        }

        if (class_exists(WebhookManagerServiceProvider::class)) {
            $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-webhook-manager/database/migrations');
        }

        // The sibling bridges boot via app->booted() callbacks that already
        // fired during app creation, before this suite's providers were all
        // registered. Re-run them now that everything is in place; unlike
        // bootAddon() these carry their own `$booted` guard, so a repeat
        // invocation really is a no-op.
        app(AutomationsBridge::class)->boot(app('events'));
        app(WebhookManagerBridge::class)->boot(app('events'));
    }

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        if (class_exists(ServiceProvider::class)) {
            $providers[] = ServiceProvider::class;
        }

        if (class_exists(WebhookManagerServiceProvider::class)) {
            $providers[] = WebhookManagerServiceProvider::class;
        }

        return $providers;
    }
}
