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
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
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
}
