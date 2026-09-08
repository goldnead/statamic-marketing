<?php

/**
 * A Control Panel that survives the install it was given.
 *
 * On 03.09.2026 sibling addons answered HTTP 500 on the public demo because
 * they shipped and their migrations had never run — `no such table`, thrown by
 * the first query of the listing. These tests reproduce that database
 * (everything present except marketing's own tables) and hold every index page
 * to two things: an empty state instead of the crash, and a line in the log
 * saying why.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;

beforeEach(function (): void {
    // The eloquent driver, because it is the mode in which every table below
    // is actually read. The flat default is asserted on its own at the bottom.
    config()->set('marketing.storage.driver', 'eloquent');

    $this->actingAs(tap(User::make()->email('setup@example.test')->makeSuper())->save());
});

/**
 * Run something against a database on which nothing has ever been migrated.
 *
 * An empty second connection rather than `Schema::drop()` on the real one, and
 * the reason is worth writing down: testbench rolls its registered migrations
 * back when the application is torn down, and a `down()` cannot reverse a table
 * that is no longer there. Dropping left every case after the first one failing
 * in teardown instead of in its assertion — the worst place to look for it.
 *
 * An empty database is also the more faithful picture of the install this whole
 * feature exists for: the addon is there, the tables never were.
 */
function withUnmigratedDatabase(callable $work): mixed
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

/**
 * The Inertia page object behind a CP response.
 *
 * Asked for as an Inertia XHR, exactly as CpRoutesTest does: the full-page
 * response would need the host application's root view, which testbench's
 * skeleton does not have.
 */
function marketingSetupPage(string $route): array
{
    $response = test()->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route));

    $response->assertOk();

    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
}

dataset('guarded index pages', [
    'dashboard' => ['marketing.dashboard', 'marketing_subscriptions', 'marketing::Dashboard'],
    'lists' => ['marketing.lists.index', 'marketing_subscriptions', 'marketing::Lists/Index'],
    'campaigns' => ['marketing.campaigns.index', 'marketing_messages', 'marketing::Campaigns/Index'],
    'sequences' => ['marketing.sequences.index', 'marketing_sequences', 'marketing::Sequences/Index'],
    'templates' => ['marketing.templates.index', 'marketing_templates', 'marketing::Templates/Index'],
]);

it('answers 200 rather than 500 when its tables are missing', function (string $route): void {
    withUnmigratedDatabase(
        fn () => $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route))->assertOk()
    );
})->with('guarded index pages');

it('renders the setup screen and names the missing table', function (string $route, string $table): void {
    $page = withUnmigratedDatabase(fn () => marketingSetupPage($route));

    expect($page['component'])->toBe('marketing::SetupRequired')
        ->and($page['props']['tables'])->toContain($table)
        ->and($page['props']['heading'])->not->toBeEmpty()
        ->and($page['props']['description'])->not->toBeEmpty()
        ->and($page['props']['title'])->not->toBeEmpty();
})->with('guarded index pages');

/**
 * The point of the guard is a readable page, not a quiet one. If this goes red
 * the addon has traded a visible 500 for a silent nothing, and an install that
 * looks finished but never works is the worse of the two.
 */
it('writes the reason to the log', function (string $route): void {
    Log::spy();

    withUnmigratedDatabase(
        fn () => $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route))->assertOk()
    );

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'marketing')
            && str_contains($message, 'php artisan migrate'))
        ->once();
})->with('guarded index pages');

it('still renders the listing on a migrated install', function (string $route, string $table, string $component): void {
    expect(marketingSetupPage($route)['component'])->toBe($component);
})->with('guarded index pages');

/**
 * A flat-file install is not an unfinished one.
 *
 * Lists, campaigns and layouts live in YAML under the shipped default driver,
 * and their tables are never read there. Naming them in the guard anyway would
 * send an operator to `php artisan migrate` for a table their site does not
 * use — which is a wrong sentence, not a safer one.
 */
it('does not ask a flat-file install to migrate a table it never reads', function (): void {
    config()->set('marketing.storage.driver', 'flat');

    $page = withUnmigratedDatabase(fn () => marketingSetupPage('marketing.templates.index'));

    expect($page['component'])->toBe('marketing::Templates/Index');
});
