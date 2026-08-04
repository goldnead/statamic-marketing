<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A marketing message that no campaign is behind.
 *
 * Until now every row in `marketing_messages` was a campaign delivery, and
 * `campaign_handle` said so: NOT NULL, indexed, and the join every report
 * starts from. The template mode of `marketing.send_email` sends a managed
 * email template (`et_templates`) to one recipient, and there is no campaign
 * anywhere in that — not a draft one, not a hidden one, not a synthetic handle
 * that resolves to nothing.
 *
 * So the column is made nullable and NULL is given a meaning: *this mail
 * belongs to no campaign*. That is the honest encoding and it is also the
 * useful one. `Message::forCampaign()` and every campaign report are a
 * `where campaign_handle = ?`, so a NULL row is invisible to all of them
 * without a single one of them being changed — which is correct, because a
 * template send is not part of any campaign's numbers. A placeholder string
 * would have been included in each of those queries as a campaign that does
 * not exist.
 *
 * `template_handle` is added beside it so the row still says what it was.
 * Nullable in both directions: a campaign send leaves it NULL, a template send
 * leaves `campaign_handle` NULL, and exactly one of the two is always set.
 * Without it, "which mail was this" would be unanswerable from the row — the
 * one question a bounce, a complaint or a support request always starts with.
 *
 * The index mirrors `campaign_handle`'s: "every message of template X" is the
 * same access path as "every message of campaign X", and the reports that get
 * written next will want it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketing_messages', 'template_handle')) {
            Schema::table('marketing_messages', function (Blueprint $table) {
                $table->string('template_handle')->nullable()->after('campaign_handle');
            });
        }

        // Its own statement, and after the column is added: SQLite rebuilds the
        // whole table for a `change()`, so anything else in the same Blueprint
        // would be compiled against the shape the rebuild is replacing.
        Schema::table('marketing_messages', function (Blueprint $table) {
            $table->string('campaign_handle')->nullable()->change();
        });

        // Last, and guarded. SQLite's table rebuild above carries the existing
        // indexes over; adding this one before it would mean re-creating an
        // index the rebuild has already emitted, which MySQL answers with
        // SQLSTATE 42000.
        if (! $this->hasIndex('marketing_messages_template_handle_index')) {
            Schema::table('marketing_messages', function (Blueprint $table) {
                $table->index('template_handle', 'marketing_messages_template_handle_index');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('marketing_messages_template_handle_index')) {
            Schema::table('marketing_messages', function (Blueprint $table) {
                // Index first: SQLite refuses to drop a column an index still
                // covers (same reason as 2026_08_01_000001).
                $table->dropIndex('marketing_messages_template_handle_index');
            });
        }

        if (Schema::hasColumn('marketing_messages', 'template_handle')) {
            Schema::table('marketing_messages', function (Blueprint $table) {
                $table->dropColumn('template_handle');
            });
        }

        // The old shape cannot hold a message without a campaign, and these
        // rows are real deliveries with real opens, clicks and bounces hanging
        // off them. They are emptied rather than deleted: a rollback is a
        // schema decision and may not throw a recipient's mail history away.
        DB::table('marketing_messages')->whereNull('campaign_handle')->update(['campaign_handle' => '']);

        Schema::table('marketing_messages', function (Blueprint $table) {
            $table->string('campaign_handle')->nullable(false)->change();
        });
    }

    protected function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('marketing_messages') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
