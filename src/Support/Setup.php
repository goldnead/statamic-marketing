<?php

namespace Goldnead\Marketing\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP listing runs before its first query.
 *
 * On 03.09.2026 sibling addons answered HTTP 500 on the public demo for one
 * reason: they were installed and their migrations had never run, so the first
 * query on the listing threw `no such table`. Marketing has the same shape —
 * `/cp/marketing` counts subscriptions before it renders anything — and the
 * same answer is owed here. A missing table is an operator's unfinished setup,
 * not a bug, and it deserves a sentence rather than a stack trace.
 *
 * The reason must not vanish with the 500, though: every guarded page that
 * turns somebody away writes why to the log first. A page that renders an
 * empty state and says nothing anywhere would be worse than the crash it
 * replaced — the install would look finished and never work.
 */
final class Setup
{
    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $existing = self::existingTables();

        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! in_array($table, $existing, true)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-marketing: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('marketing::SetupRequired', [
            'title' => $title,
            'heading' => __('marketing::setup.setup_required_heading'),
            'description' => __('marketing::setup.setup_required_description'),
            'tables' => $missing,
        ]);
    }

    /**
     * Those of the given tables that this install actually reads.
     *
     * Lists, campaigns and layouts are *definitions*, and marketing keeps them
     * in YAML by default (`marketing.storage.driver`, which ships as `flat`).
     * On such an install `marketing_lists` does not exist and is never asked
     * for, so naming it in a guard would send an operator to `php artisan
     * migrate` for a table their site will never read — a wrong sentence is not
     * better than a stack trace. Subscriptions, messages and events have no
     * flat driver; they are guarded unconditionally.
     *
     * @return list<string>
     */
    public static function definitionTables(string ...$tables): array
    {
        return config('marketing.storage.driver', 'flat') === 'eloquent'
            ? array_values($tables)
            : [];
    }

    /**
     * Every table this connection has, read in one go.
     *
     * `Schema::hasTable()` asks the database once per table, and the guard
     * above is asked about up to five of them on the widest page — five schema
     * round trips before the dashboard has drawn anything, on every single
     * load, to answer a question that is only ever interesting on an install
     * whose migrations never ran. `Schema::getTables()` answers the same
     * question for all of them at a fixed cost, measured at two queries on
     * SQLite and one information_schema read on MySQL.
     *
     * Deliberately NOT `getTableListing()`: since Laravel 13 that qualifies
     * every name with its schema (`main.marketing_subscriptions` on SQLite),
     * so a plain comparison against the names the callers pass would report
     * every table as missing — an addon that answers "run php artisan migrate"
     * on a fully migrated install. `getTables()` returns the bare name; the
     * strip below is belt and braces for the drivers that qualify it anyway.
     *
     * Deliberately not memoized either: one guard runs per request, so a
     * static cache would buy nothing and would go stale the moment a test or a
     * migration changed the schema inside the same process.
     *
     * @return list<string>
     */
    protected static function existingTables(): array
    {
        return array_values(array_map(
            static function (array $table): string {
                $name = (string) ($table['name'] ?? '');

                return str_contains($name, '.')
                    ? substr($name, strrpos($name, '.') + 1)
                    : $name;
            },
            Schema::getTables()
        ));
    }
}
