<?php

namespace Goldnead\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Providers\StatamicServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Marketing runtime tables + LeadHub tables (hard dependency).
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-leadhub/database/migrations');

        // Statamic runs bootAddon() inside Statamic::booted callbacks that
        // orchestra/testbench never fires — force them so Nav, permissions,
        // views, and migrations register (see LeadHub's TestCase).
        $this->app->getProvider(\Goldnead\Leadhub\ServiceProvider::class)?->bootAddon();
        $this->app->getProvider(\Goldnead\Marketing\ServiceProvider::class)?->bootAddon();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\Leadhub\ServiceProvider::class,
            \Goldnead\Marketing\ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'noreply@example.com', 'name' => 'Test']);

        // LeadHub: always eloquent in this suite (its tables are migrated above).
        $app['config']->set('leadhub.storage.driver', 'eloquent');

        // Marketing driver: flip via MARKETING_DRIVER for the flat/eloquent matrix.
        $app['config']->set('marketing.storage.driver', env('MARKETING_DRIVER', 'flat'));

        $tmpRoot = sys_get_temp_dir().'/marketing-test-'.getmypid();
        $app['config']->set('marketing.storage.flat.path', $tmpRoot.'/content');
    }

    protected function tearDown(): void
    {
        // The flat store writes real files — wipe them between tests so
        // repositories never leak state across cases.
        $tmpRoot = sys_get_temp_dir().'/marketing-test-'.getmypid();

        if (is_dir($tmpRoot)) {
            $this->deleteDirectory($tmpRoot);
        }

        parent::tearDown();
    }

    protected function deleteDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_dir($file) ? $this->deleteDirectory($file) : unlink($file);
        }

        @rmdir($dir);
    }

    /**
     * Mount the addon's CP routes under the production `/cp` prefix and
     * `statamic.cp.` name prefix, plus the public web routes.
     */
    protected function defineRoutes($router): void
    {
        $router->name('statamic.cp.')
            ->prefix('cp')
            ->group(__DIR__.'/../routes/cp.php');

        $router->middleware('web')->group(__DIR__.'/../routes/web.php');
    }
}
