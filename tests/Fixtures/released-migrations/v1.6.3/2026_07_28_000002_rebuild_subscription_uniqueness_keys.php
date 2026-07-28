<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds the consent unique on a fixed-width key.
 *
 * `ms_brand_list_email_unique` spanned (brand_id, list_handle,
 * email_normalized). Under utf8mb4 each `varchar(255)` costs 1020 bytes, so the
 * index MySQL builds is 2048 of the 3072 InnoDB allows — accepted today, and
 * two thirds spent before anyone adds a fourth column to the tuple. This is the
 * addon's most important constraint (one address, one list, one brand, one
 * consent record), which is the last one that should be one schema change away
 * from being unbuildable.
 *
 * The correction is in the original migrations as well, so a new install never
 * creates the wide index in the first place. That reaches nobody who already
 * ran them, which is what this migration is for. It is idempotent: on a fresh
 * install the column and the index are already in their new shape and it does
 * nothing.
 *
 * No duplicate rows can surface here. The new key is a pure function of the two
 * columns the old unique already covered, and neither of them is nullable, so
 * the old index constrained every row and two rows cannot collide under the new
 * one without having collided under the old one.
 */
return new class extends Migration
{
    private const INDEX = 'ms_brand_list_email_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('marketing_subscriptions', 'uniqueness_key')) {
            Schema::table('marketing_subscriptions', function (Blueprint $table) {
                // Nullable first: the rows are already there and have no key yet.
                $table->string('uniqueness_key', 64)->nullable()->after('email_normalized');
            });

            $this->backfill();

            Schema::table('marketing_subscriptions', function (Blueprint $table) {
                // A unique does not constrain NULL. Leaving this nullable would
                // leave an index that reads as a guarantee and is not one.
                $table->string('uniqueness_key', 64)->nullable(false)->change();
            });
        } else {
            // The column exists but rows might predate it on an install that
            // took the new migrations partially. Cheap and safe either way.
            $this->backfill();
        }

        $existing = collect(Schema::getIndexes('marketing_subscriptions'))
            ->first(fn (array $index) => $index['name'] === self::INDEX);

        if ($existing && $existing['columns'] === ['brand_id', 'uniqueness_key']) {
            return;
        }

        Schema::table('marketing_subscriptions', function (Blueprint $table) use ($existing) {
            if ($existing) {
                $table->dropUnique(self::INDEX);
            }

            $table->unique(['brand_id', 'uniqueness_key'], self::INDEX);
        });
    }

    public function down(): void
    {
        $existing = collect(Schema::getIndexes('marketing_subscriptions'))
            ->first(fn (array $index) => $index['name'] === self::INDEX);

        Schema::table('marketing_subscriptions', function (Blueprint $table) use ($existing) {
            if ($existing) {
                $table->dropUnique(self::INDEX);
            }

            $table->unique(['brand_id', 'list_handle', 'email_normalized'], self::INDEX);
        });

        // The column is left in place. On a fresh install it belongs to the
        // create-table migration, not to this one, and dropping it here would
        // tear a column out from under a migration that still expects it.
    }

    /**
     * Fills `uniqueness_key` for every row that has none.
     *
     * Computed from `email_normalized` rather than from `email`, which is what
     * `Subscription::uniquenessKeyFor()` produces too — it normalizes its
     * argument before hashing, and `email_normalized` is that same value,
     * maintained by the model since the table existed.
     */
    private function backfill(): void
    {
        DB::table('marketing_subscriptions')
            ->whereNull('uniqueness_key')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('marketing_subscriptions')
                        ->where('id', $row->id)
                        ->update([
                            'uniqueness_key' => hash('sha256', implode("\0", [
                                (string) $row->list_handle,
                                (string) $row->email_normalized,
                            ])),
                        ]);
                }
            });
    }
};
