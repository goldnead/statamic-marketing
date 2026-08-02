<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The newsletter web archive: one flag per campaign.
 *
 * `false` is the default and it is the whole safety property of this feature.
 * Every campaign that already exists keeps it, so applying this migration
 * publishes nothing — an editor has to release each campaign by hand. The
 * opposite default would mean a `composer update` put a year of segment-priced,
 * individually addressed mail on the open web.
 *
 * No index. The archive index reads all campaigns of a brand and filters in
 * PHP, because a site has tens of campaigns rather than millions of rows, and
 * because the flat driver has no index to offer either — one behaviour for both
 * drivers is worth more here than a scan avoided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->boolean('in_archive')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn('in_archive');
        });
    }
};
