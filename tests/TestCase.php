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

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());

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

    /**
     * In-memory SQLite by default, so the suite keeps running anywhere with no
     * setup. Set `DB_DRIVER=mysql` to point the identical suite at a real MySQL
     * server instead — see phpunit.mysql.xml.
     *
     * SQLite is not a substitute for that run. It has no InnoDB key-length
     * limit, no utf8mb4 byte arithmetic and no fixed column widths, which is
     * exactly why a fully green suite in statamic-notifications let an
     * unbuildable index reach production. `tests/Unit/IndexKeyLengthTest.php`
     * closes that gap without a server; this closes it with one.
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'marketing_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
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
     *
     * SubstituteBindings is not decoration. It is part of Statamic's real CP
     * middleware group, and it is the middleware that applies any
     * `Route::bind()` a sibling addon has registered. Route-model bindings are
     * application-wide, not per package: a binding another addon registers for
     * `{template}` or `{rule}` applies to every route with that parameter name
     * in every installed addon, including this one. Without this middleware
     * here, every such binding was inert in the test bed — so a parameter name
     * that collides with a sibling's binding passed the whole suite and then
     * 404'd, silently, on any Hub that has both addons. That is exactly how
     * goldnead/statamic-leadhub 1.8.0 shipped a delete button that did nothing.
     *
     * The web routes already get it via the `web` group.
     */
    protected function defineRoutes($router): void
    {
        $router->name('statamic.cp.')
            ->prefix('cp')
            ->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
            ->group(__DIR__.'/../routes/cp.php');

        $router->middleware('web')->group(__DIR__.'/../routes/web.php');
        $this->mountStandInSiblingRoutes($router);
    }

    /**
     * Stand-ins for the routes of a sibling addon installed next to this one.
     *
     * They belong to the bed rather than to the test that reads them because a
     * sibling registers its routes the same way this addon does: at boot, and
     * therefore ahead of Statamic's `{segments?}` frontend catch-all. A route
     * added later — from inside a test body — is shadowed by that catch-all and
     * answers 404 no matter what the bindings do, which would make the check
     * pass for the wrong reason.
     *
     * Each one does nothing but echo its own parameter. If this addon ever
     * binds a name they use, the echo stops happening: the binder resolves the
     * value against a repository here first, finds nothing, and aborts 404 —
     * precisely what LeadHub's delete button did.
     *
     * @see \Goldnead\\Marketing\\Tests\Feature\RouteParameterCollisionTest
     */
    protected function mountStandInSiblingRoutes($router): void
    {
        $router->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
            ->group(function ($router) {
                foreach (static::NAMES_A_SIBLING_MIGHT_USE as $name) {
                    $router->get(
                        'sibling-probe/'.$name.'/{'.$name.'}',
                        fn ($value) => (string) $value
                    );
                }
            });
    }

    /**
     * Generic names a sibling addon could plausibly put in one of its own
     * routes. None of them is bound by anything in this application today —
     * `rule` and `template` were claimed by statamic-webhook-manager until its
     * 1.7.0 and `automation` by statamic-automations until its 1.6.0. They are
     * here because a sibling reaching for one is what the rule prevents.
     *
     * @var list<string>
     */
    public const NAMES_A_SIBLING_MIGHT_USE = [
        'automation', 'rule', 'template', 'webhook', 'endpoint', 'handle', 'id', 'slug', 'record',
    ];

}
