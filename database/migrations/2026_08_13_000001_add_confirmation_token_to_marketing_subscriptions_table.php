<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The double-opt-in link gets a key of its own.
 *
 * Until now one `token` column answered for everything a subscriber can reach
 * without a session: confirm, unsubscribe, and the preference centre. That
 * single value is printed in the footer of every campaign, so it is the most
 * widely copied string this system owns — and it was also the thing that
 * granted consent. It was set once with `??=` and never rotated, and
 * `confirmByToken()` refused an already-subscribed row but said nothing about
 * an unsubscribed one. A confirmation link from two years ago, sitting in a
 * mailbox, a backup or a link scanner's history, therefore put an address that
 * had since left back onto the list, with no act by its owner and no new
 * consent record to show for it.
 *
 * Splitting the two is what makes the confirm link expirable, single-use and
 * revocable without touching the footer links of every campaign already sent.
 *
 * NOT NULL, like `token` beside it, and for the reason the original migration
 * gives one column further up: a unique does not constrain NULL, so a nullable
 * unique is an index that reads as a rule and enforces nothing for exactly the
 * rows it exists for. Whether a link was ever ISSUED is therefore not
 * expressed by the absence of a token — every row has one, unguessable and
 * never printed — but by `confirmation_sent_at`, which is the only thing
 * `confirmByToken()` accepts as proof that a link went out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_subscriptions', function (Blueprint $table) {
            // Nullable for the moment, because existing rows have no value yet
            // and each one needs a DIFFERENT one. Tightened at the end.
            $table->string('confirmation_token', 64)->nullable()->after('token');
            $table->timestamp('confirmation_sent_at')->nullable()->after('confirmation_token');
            $table->timestamp('confirmation_used_at')->nullable()->after('confirmation_sent_at');
        });

        /*
         * Carry the existing state across, deliberately unevenly.
         *
         * Pending rows keep a working link: somebody is holding a confirmation
         * mail sent in good faith minutes ago, and invalidating it would
         * strand them. Their existing token is copied, so the URL already in
         * their inbox continues to resolve.
         *
         * Subscribed rows get the same value marked as spent, so a repeat
         * click on an old link still finds its row and still renders the
         * friendly "you are confirmed" page rather than a bare 404 — while
         * granting nothing, because the row is already subscribed.
         *
         * Unsubscribed, bounced and complained rows are handled below and get
         * a value that has never been anywhere, which is the entire point of
         * this migration: every confirmation link ever issued to an address
         * that has since left stops working at this line.
         *
         * Written through the query builder rather than the model: the model
         * carries HasBrand's global scope, and a data migration has to see
         * every brand's rows, not the current request's.
         */
        /*
         * COALESCE on the date because `subscribed_at` is nullable. Every row
         * this addon writes has it, but a row created by an import, a seed or
         * another addon calling `Subscription::create()` directly need not —
         * and a pending row that came out of here with a NULL
         * `confirmation_sent_at` would have its in-flight link refused as
         * "never mailed", which is precisely the person this branch exists to
         * protect.
         */
        DB::table('marketing_subscriptions')
            ->where('status', 'pending')
            ->update([
                'confirmation_token' => DB::raw('token'),
                'confirmation_sent_at' => DB::raw('COALESCE(subscribed_at, created_at)'),
            ]);

        // COALESCE, because `confirmation_used_at` is what marks the link
        // spent: a subscribed row that somehow carries no `confirmed_at` would
        // otherwise come out of this migration looking unused and would let an
        // old link run the confirmation path again.
        $jetzt = DB::getPdo()->quote((string) now());

        DB::table('marketing_subscriptions')
            ->where('status', 'subscribed')
            ->update([
                'confirmation_token' => DB::raw('token'),
                'confirmation_sent_at' => DB::raw('subscribed_at'),
                'confirmation_used_at' => DB::raw("COALESCE(confirmed_at, subscribed_at, {$jetzt})"),
            ]);

        /*
         * Everyone else — unsubscribed, bounced, complained, and any row whose
         * status the copies above did not cover — gets a fresh value, one at a
         * time because each has to differ from every other. `confirmation_sent_at`
         * stays NULL for them, which is what tells `confirmByToken()` that no
         * link was ever issued for this row and that nothing here is redeemable.
         */
        /*
         * `chunkById`, not `chunk`, and the difference is the whole migration.
         *
         * `chunk()` pages with OFFSET over the query it is given — and this
         * query is `whereNull('confirmation_token')`, which the callback is
         * busy emptying. After the first 500 rows are filled they leave the
         * result set, the second page skips to offset 500 of a set that has
         * shrunk by 500, and every second block of rows is never visited.
         * Half the table would still be NULL when the NOT NULL below runs,
         * and MySQL has no transactional DDL to undo the columns already
         * added. `chunkById` walks the primary key instead and cannot skip.
         *
         * This is the same reasoning, and the same call, as the backfill in
         * 2026_07_28_000002_rebuild_subscription_uniqueness_keys.
         */
        DB::table('marketing_subscriptions')
            ->whereNull('confirmation_token')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('marketing_subscriptions')
                        ->where('id', $row->id)
                        ->update(['confirmation_token' => Str::random(48)]);
                }
            });

        // Nothing may be left, or the NOT NULL below fails halfway through a
        // migration that cannot be rolled back. Cheap, indexed, and it turns a
        // silent half-migration into a refusal that says why.
        $offen = DB::table('marketing_subscriptions')->whereNull('confirmation_token')->count();

        if ($offen > 0) {
            throw new RuntimeException(
                "Backfill unvollständig: {$offen} Zeilen ohne confirmation_token. ".
                'Die NOT-NULL-Umstellung wird nicht versucht.'
            );
        }

        Schema::table('marketing_subscriptions', function (Blueprint $table) {
            $table->string('confirmation_token', 64)->nullable(false)->change();
            $table->unique('confirmation_token');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['confirmation_token']);
            $table->dropColumn(['confirmation_token', 'confirmation_sent_at', 'confirmation_used_at']);
        });
    }
};
