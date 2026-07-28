<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the across-all-brands unique on `marketing_lists.handle`.
 *
 * The brand-scoping migration turned every handle unique into
 * (brand_id, handle) — correct for campaigns and templates, and wrong for
 * lists, because the list handle is the one value a public request carries.
 * `SetBrandFromRouteValue` derives the brand from it, and brand-context states
 * the precondition plainly: the column must be unique across all brands, and a
 * column that is only unique *per brand* must never be passed. With the
 * per-brand unique, two brands could each own a list called `newsletter`, and
 * the next public sign-up for it would raise AmbiguousBrandRecord — every
 * subscribe form for that handle dead, in both brands at once.
 *
 * The (brand_id, handle) unique stays. It is redundant under this one, but
 * dropping it would be a second schema change for no gain, and it is the index
 * campaigns and templates still rely on.
 *
 * If an install already holds the same list handle in two brands, this cannot
 * silently pick a winner: it stops and names them. That state already breaks
 * public sign-ups, so it has to be resolved by a person either way.
 */
return new class extends Migration
{
    private const INDEX = 'marketing_lists_handle_unique_all_brands';

    public function up(): void
    {
        $duplicates = DB::table('marketing_lists')
            ->select('handle')
            ->groupBy('handle')
            ->havingRaw('count(*) > 1')
            ->pluck('handle');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot make marketing list handles unique across brands: '
                .'more than one brand holds ['.$duplicates->implode(', ').']. '
                .'The public subscribe endpoint derives the brand from the list handle, '
                .'so a handle can only have one owner. Rename or remove the duplicates '
                .'and run the migration again.'
            );
        }

        Schema::table('marketing_lists', function (Blueprint $table) {
            $table->unique('handle', self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_lists', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }
};
