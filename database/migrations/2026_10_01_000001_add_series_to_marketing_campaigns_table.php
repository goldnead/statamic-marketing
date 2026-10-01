<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The series columns: where a campaign came from, and what it carries along.
 *
 * `series` is the handle of the template campaign (status `series`) a campaign
 * was cloned from; `source_key` names the term it belongs to
 * (`occurrence:<uuid>`). Both null on every campaign that is nobody's child —
 * which is every campaign that exists today.
 *
 * `meta` holds the moment snapshot of the term (`meta['event']`: city, venue,
 * date, tickets link …) that `{{ event:city }}` renders, and on the template
 * itself the series settings (`meta['series']`: radius, days before, send
 * time). JSON rather than columns because every reader treats it as one block
 * and no query filters inside it; the pair (`series`, `source_key`) is what
 * the idempotent sync looks rows up by, and that is the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('marketing_campaigns', 'series')) {
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table) {
            // Fixed widths, not the 255 default: the pair is the sync's lookup
            // index, and a `series` handle of 128 plus a 64-byte source key
            // (`occurrence:` + uuid) keeps that index at a quarter of InnoDB's
            // key budget rather than two thirds of it. Same discipline as
            // `uniqueness_key` on the subscriptions.
            $table->string('series', 128)->nullable()->after('ab_share');
            $table->string('source_key', 64)->nullable()->after('series');
            $table->json('meta')->nullable()->after('source_key');
            $table->index(['series', 'source_key'], 'marketing_campaigns_series_source_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('marketing_campaigns', 'series')) {
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropIndex('marketing_campaigns_series_source_idx');
            $table->dropColumn(['series', 'source_key', 'meta']);
        });
    }
};
