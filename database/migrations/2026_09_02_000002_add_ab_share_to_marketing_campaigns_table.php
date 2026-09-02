<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The share of the audience an A/B test is run on.
 *
 * `0` is the default and means what every campaign does today: with a
 * `variant_subject` the whole audience is split half and half; without one
 * there is no test. A value between 10 and 50 says "test on this many
 * percent, then send the winner to the rest" — which the send path does not
 * do yet. The column and its validation ship first so a campaign written now
 * carries the answer when the winner send arrives (Phase 2, see README).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('marketing_campaigns', 'ab_share')) {
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->unsignedTinyInteger('ab_share')->default(0)->after('variant_subject');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('marketing_campaigns', 'ab_share')) {
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn('ab_share');
        });
    }
};
