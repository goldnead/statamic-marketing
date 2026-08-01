<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign A/B: the variant subject on the campaign, the assigned variant on
 * the message.
 *
 * Both columns are nullable, and NULL is the meaningful default: a campaign
 * with no `variant_subject` is not an A/B test, and a message with no `variant`
 * did not take part in one. Every campaign that exists today keeps both as NULL
 * and behaves exactly as it did before.
 *
 * The variant lives on `marketing_messages` rather than anywhere else because
 * that is the row every open, click, bounce and unsubscribe already resolves
 * to. Putting it there makes the whole existing measurement chain
 * variant-aware without touching the tracking endpoints or their signatures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->string('variant_subject')->nullable()->after('subject');
        });

        Schema::table('marketing_messages', function (Blueprint $table) {
            // Deliberately narrow. It holds a bucket label ('a' / 'b'), not a
            // name, and it is half of a composite index — every byte of width
            // is spent on the InnoDB key limit (see IndexKeyLengthTest).
            $table->string('variant', 8)->nullable()->after('email');
        });

        Schema::table('marketing_messages', function (Blueprint $table) {
            // The report's only new access path: "all messages of campaign X in
            // variant Y". A lone index on `variant` would be worthless — it has
            // two values.
            $table->index(['campaign_handle', 'variant'], 'marketing_messages_campaign_variant_index');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table) {
            // Index first: SQLite refuses to drop a column an index still
            // covers (same reason as 2026_07_03_000001).
            $table->dropIndex('marketing_messages_campaign_variant_index');
            $table->dropColumn('variant');
        });

        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn('variant_subject');
        });
    }
};
