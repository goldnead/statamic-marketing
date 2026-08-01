<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1 brand-scoping for the marketing addon.
 *
 * Adds `brand_id` to every marketing table, backfills existing rows onto the
 * default brand (goldnead/statamic-brand-context guarantees it exists), and
 * reworks every business-key unique to be brand-scoped.
 *
 * The critical rework is marketing_subscriptions: the DOI/consent uniqueness
 * moves from (list_handle, email_normalized) to a brand-scoped unique. Without
 * this, brand A holding a subscription for an address would block that same
 * address from subscribing — and holding independent consent — in brand B
 * (consent bleed).
 *
 * ---------------------------------------------------------------------------
 * This migration was published in 1.3.0 and rewritten in 1.6.1. Everything
 * below is written for the fact that it therefore has to be correct for three
 * different databases at once.
 *
 * **Never ran it.** Two shapes, and 1.6.1 only got one of them right. On a
 * fresh install `uniqueness_key` exists, because 1.6.1 added it to the
 * create-table migration, and the consent unique can be built on it directly.
 * On an install created before 1.3.0 the table predates that column, the
 * create-table migration is recorded as run and will never run again, and
 * `2026_07_28_000002` — which adds the column — sorts *after* this file. 1.6.1
 * built the unique over `uniqueness_key` unconditionally, so on that install it
 * named a column that does not exist yet.
 *
 * The consequences were engine-specific and both bad. MySQL refuses with
 * ERROR 1072. SQLite reads a double-quoted identifier that resolves to nothing
 * as a *string literal*, so `create unique index … ("brand_id",
 * "uniqueness_key")` silently became a unique over `brand_id` and a constant —
 * unique on the brand alone. Empty table: accepted, then repaired seconds later
 * by `2026_07_28_000002` in the same `migrate` run, which is why every test
 * install and the hub sailed through it. Table with rows: the second row
 * collides and the statement fails.
 *
 * Either way the statement *before* it had already dropped
 * (list_handle, email_normalized), no engine rolls DDL back, and this
 * migration is not recorded as run. What that leaves behind is a subscriptions
 * table with no consent unique of any kind and an install that does not know
 * it: double sign-ups for the same address on the same list are accepted from
 * that moment on. `php artisan marketing:consent-integrity` reports exactly
 * this state.
 *
 * **Ran it successfully.** Untouched — it will never run again. Whichever of
 * the two consent uniques it built, `2026_07_28_000002` converts to the final
 * shape.
 *
 * **Stopped in the middle of it.** The one that has to be picked back up, and
 * the reason every step below is guarded and re-runnable rather than merely
 * ordered correctly. Ordering alone would fix the next install and leave every
 * install that already broke exactly as broken as it is, because a second run
 * of the 1.6.1 file fails on `duplicate column name: brand_id` — a different
 * error, from step 1, which says nothing about the missing unique and sends
 * whoever reads it looking in the wrong place.
 * ---------------------------------------------------------------------------
 */
return new class extends Migration
{
    /**
     * Root tables whose global `handle` unique becomes (brand_id, handle).
     */
    private array $handleTables = [
        'marketing_lists',
        'marketing_templates',
        'marketing_campaigns',
    ];

    /**
     * Child/log tables: brand_id denormalised for query-time defense only.
     */
    private array $childTables = [
        'marketing_messages',
        'marketing_message_events',
    ];

    private const CONSENT_INDEX = 'ms_brand_list_email_unique';

    public function up(): void
    {
        $allTables = $this->allTables();

        // 1. Add brand_id (nullable first so existing rows survive) + index.
        //    Skipped per table where it is already there: after an interrupted
        //    run some tables have it and some do not.
        foreach ($allTables as $table) {
            if (Schema::hasColumn($table, 'brand_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('brand_id')->nullable()->after('id')->index();
            });
        }

        // 2. Backfill every existing row onto the default brand.
        $defaultBrandId = DB::table('brands')->where('is_default', true)->value('id')
            ?? DB::table('brands')->where('handle', config('brand-context.default_handle', 'default'))->value('id');

        foreach ($allTables as $table) {
            DB::table($table)->whereNull('brand_id')->update(['brand_id' => $defaultBrandId]);
        }

        // 3. Rework the business-key uniques to be brand-scoped.
        foreach ($this->handleTables as $table) {
            $this->replaceUnique(
                table: $table,
                drop: [$table.'_handle_unique'],
                columns: ['brand_id', 'handle'],
                name: $table.'_brand_id_handle_unique',
            );
        }

        // 3b. The critical consent unique.
        //
        // Built on `uniqueness_key` where that column exists. The natural tuple
        // measured 2048 of InnoDB's 3072 index bytes; the hash measures 264
        // with brand_id.
        //
        // Where it does not exist — every install created before 1.3.0 — the
        // brand-scoped natural tuple goes in instead. That is not a fallback
        // invented here: it is exactly the index 1.3.0 through 1.6.0 shipped,
        // it fits InnoDB, and `2026_07_28_000002` converts it to the hash form
        // immediately afterwards in the same `migrate` run. Referring forward
        // to a column a later migration creates is the defect; letting that
        // migration do its own work is the fix.
        $consentColumns = Schema::hasColumn('marketing_subscriptions', 'uniqueness_key')
            ? ['brand_id', 'uniqueness_key']
            : ['brand_id', 'list_handle', 'email_normalized'];

        $this->replaceUnique(
            table: 'marketing_subscriptions',
            drop: ['marketing_subscriptions_list_handle_email_normalized_unique'],
            columns: $consentColumns,
            // Explicit short name: the auto-generated one exceeds MySQL's
            // 64-char identifier limit.
            name: self::CONSENT_INDEX,
        );

        // 4. Now that all rows carry a brand, enforce NOT NULL.
        //
        //    Not cosmetic: every unique built above leads with `brand_id`, and
        //    no engine constrains a row whose indexed column is NULL. A
        //    nullable brand_id here would leave the consent unique reading like
        //    a guarantee for exactly the rows it does not cover.
        //
        //    Unconditional, unlike everything above it. Restating a column that
        //    is already NOT NULL is accepted by both engines and changes
        //    nothing, so there is no state to check for — and a `hasColumn`
        //    style guard here would have to answer a question about a column
        //    this same migration created a few statements earlier, which is
        //    exactly the kind of read a schema probe cannot model.
        foreach ($allTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('brand_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        // Restore the global uniques.
        foreach ($this->handleTables as $table) {
            $this->replaceUnique(
                table: $table,
                drop: [$table.'_brand_id_handle_unique'],
                columns: ['handle'],
                name: $table.'_handle_unique',
            );
        }

        $this->replaceUnique(
            table: 'marketing_subscriptions',
            drop: [self::CONSENT_INDEX],
            columns: ['list_handle', 'email_normalized'],
            name: 'marketing_subscriptions_list_handle_email_normalized_unique',
        );

        // Drop the brand_id index + column.
        foreach ($this->allTables() as $table) {
            if (! Schema::hasColumn($table, 'brand_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_brand_id_index');
                $blueprint->dropColumn('brand_id');
            });
        }
    }

    /**
     * @return list<string>
     */
    private function allTables(): array
    {
        return array_merge(
            $this->handleTables,
            ['marketing_subscriptions'],
            $this->childTables,
        );
    }

    /**
     * Swap one unique index for another, in whatever state the table is in.
     *
     * Drops only indexes that are actually there, does nothing at all if the
     * wanted index already exists over the wanted columns, and refuses — by
     * name, with the offending values — rather than letting the driver fail
     * with a bare integrity error if the rows cannot carry the new index.
     *
     * @param  list<string>  $drop  indexes superseded by this one
     * @param  list<string>  $columns  the new index
     */
    private function replaceUnique(string $table, array $drop, array $columns, string $name): void
    {
        $indexes = collect(Schema::getIndexes($table))->keyBy('name');

        $wanted = $indexes->get($name);

        if ($wanted && $wanted['columns'] === $columns && ($wanted['unique'] ?? false)) {
            return;
        }

        $this->guardAgainstDuplicates($table, $columns, $name);

        foreach ([...$drop, $name] as $obsolete) {
            if ($indexes->has($obsolete)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($obsolete));
            }
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
    }

    /**
     * Stop, and say what is in the way, if the rows cannot carry the index.
     *
     * This cannot pick a winner and must not delete anything. On the path that
     * matters — an install whose consent unique was dropped and not replaced by
     * the 1.6.1 defect — the duplicates are real sign-ups that a form accepted
     * while the constraint was missing, and which of them is the consent record
     * is a question about people, not about rows.
     *
     * @param  list<string>  $columns
     */
    private function guardAgainstDuplicates(string $table, array $columns, string $name): void
    {
        $duplicates = DB::table($table)
            ->select($columns)
            ->selectRaw('count(*) as occurrences')
            ->groupBy($columns)
            ->havingRaw('count(*) > 1')
            ->limit(25)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $listed = $duplicates
            ->map(fn ($row) => '  '.collect($columns)
                ->map(fn (string $column) => $column.'='.($row->{$column} ?? 'NULL'))
                ->implode(' ')." (x{$row->occurrences})")
            ->implode(PHP_EOL);

        throw new RuntimeException(
            "Cannot build the unique index [{$name}] on [{$table}]: rows already exist that it would reject."
            .PHP_EOL.$listed.PHP_EOL
            .'Nothing was changed and nothing was removed. On marketing_subscriptions this is the '
            .'fingerprint of an update that stopped halfway through under 1.6.1, 1.6.2 or 1.6.3 — it '
            .'dropped the consent unique and failed before replacing it, after which duplicate '
            .'sign-ups were accepted. Run `php artisan marketing:consent-integrity` for the full list '
            .'with row ids and dates, decide which record is the consent record, remove the others, '
            .'then migrate again.'
        );
    }
};
