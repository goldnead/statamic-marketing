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

        if (class_exists(ServiceProvider::class)) {
            $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-automations/database/migrations');
            $this->app->getProvider(ServiceProvider::class)?->bootAddon();
        }

        if (class_exists(WebhookManagerServiceProvider::class)) {
            $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-webhook-manager/database/migrations');
            $this->app->getProvider(WebhookManagerServiceProvider::class)?->bootAddon();
        }

        // The sibling bridges boot via app->booted() callbacks that already
        // fired during app creation — before the providers' bootAddon() calls
        // above. Re-run them now that everything is registered; their guards
        // make repeat invocations safe.
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
