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
 * moves from (list_handle, email_normalized) to
 * (brand_id, list_handle, email_normalized). Without this, brand A holding a
 * subscription for an address would block that same address from subscribing —
 * and holding independent consent — in brand B (consent bleed).
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

    public function up(): void
    {
        $allTables = array_merge(
            $this->handleTables,
            ['marketing_subscriptions'],
            $this->childTables,
        );

        // 1. Add brand_id (nullable first so existing rows survive) + index.
        foreach ($allTables as $table) {
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
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['handle']);
                $blueprint->unique(['brand_id', 'handle']);
            });
        }

        // 3b. The critical consent unique.
        Schema::table('marketing_subscriptions', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['list_handle', 'email_normalized']);
            $blueprint->unique(['brand_id', 'list_handle', 'email_normalized']);
        });

        // 4. Now that all rows carry a brand, enforce NOT NULL.
        foreach ($allTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('brand_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        $allTables = array_merge(
            $this->handleTables,
            ['marketing_subscriptions'],
            $this->childTables,
        );

        // Restore the global uniques.
        foreach ($this->handleTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['brand_id', 'handle']);
                $blueprint->unique(['handle']);
            });
        }

        Schema::table('marketing_subscriptions', function (Blueprint $blueprint) {
            $blueprint->dropUnique(['brand_id', 'list_handle', 'email_normalized']);
            $blueprint->unique(['list_handle', 'email_normalized']);
        });

        // Drop the brand_id index + column.
        foreach ($allTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_brand_id_index');
                $blueprint->dropColumn('brand_id');
            });
        }
    }
};
