<?php

namespace Goldnead\Marketing\Tests\Feature;

use Goldnead\Marketing\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\User;

/**
 * The setup screen on the CP detail pages, for an install whose tables are missing.
 *
 * Route-model binding and the first query run before a controller method
 * begins, so the guard inside `index()` never sees a detail page. The detail
 * routes carry the guard as middleware instead, and the list, template and
 * campaign tables in it are read from the config while routes/cp.php loads.
 * That is why the driver is set here, before the application boots: a
 * `config()->set()` in a `beforeEach` would arrive after the routes were built.
 *
 * {@see SetupGuardTest} keeps the index pages. Its helpers are not shared with
 * this class on purpose: a class file must not depend on functions declared in
 * another test file, which only exist when that file happens to be loaded.
 */
class SetupGuardDetailRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The mode in which every table below is actually read.
        $app['config']->set('marketing.storage.driver', 'eloquent');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(tap(User::make()->email('setup@example.test')->makeSuper())->save());
    }

    /**
     * Route, a handle that exists on a migrated install, and a table that
     * the page's guard names when that table is missing.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function detailPages(): array
    {
        return [
            'list' => ['marketing.lists.show', 'newsletter', 'marketing_subscriptions'],
            'campaign' => ['marketing.campaigns.show', 'welcome', 'marketing_messages'],
            'sequence' => ['marketing.sequences.edit', 'onboarding', 'marketing_sequences'],
            'template' => ['marketing.templates.edit', 'welcome', 'marketing_templates'],
        ];
    }

    #[DataProvider('detailPages')]
    public function test_shows_the_setup_screen_on_a_detail_page_when_its_tables_are_missing(string $route, string $handle, string $table): void
    {
        $page = $this->withUnmigratedDatabase(fn () => $this->inertiaPage($route, $handle));

        $this->assertSame('marketing::SetupRequired', $page['component']);
        $this->assertContains($table, $page['props']['tables']);
    }

    // No guest test here: this suite's routes do not carry Statamic's `statamic.cp` groups, so no login check
    // runs on them whatever they are given. That the setup screen stays behind the login is what GuardSetup
    // does for a route that carries the groups, and is tested in statamic-brand-context.

    #[DataProvider('detailPages')]
    public function test_keeps_answering_404_for_an_unknown_handle_on_a_migrated_install(string $route, string $handle, string $table): void
    {
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route($route, 'does-not-exist'))
            ->assertNotFound();
    }

    /**
     * The Inertia page object behind a CP response, asked for as an Inertia XHR
     * because the full-page response needs a root view the test skeleton lacks.
     *
     * @return array<string, mixed>
     */
    protected function inertiaPage(string $route, string $handle): array
    {
        $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route, $handle));

        $response->assertOk();

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Run something against a database on which nothing has ever been migrated.
     *
     * An empty second connection rather than `Schema::drop()` on the real one:
     * testbench rolls its migrations back at teardown, and a `down()` cannot
     * reverse a table that is no longer there.
     */
    protected function withUnmigratedDatabase(callable $work): mixed
    {
        config()->set('database.connections.unmigrated', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $previous = (string) config('database.default');

        config()->set('database.default', 'unmigrated');
        DB::setDefaultConnection('unmigrated');

        try {
            return $work();
        } finally {
            config()->set('database.default', $previous);
            DB::setDefaultConnection($previous);
            DB::purge('unmigrated');
        }
    }
}
