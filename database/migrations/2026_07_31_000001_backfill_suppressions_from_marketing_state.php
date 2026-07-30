<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Moves the deliverability facts this addon already knew into the table that
 * can actually be asked about them.
 *
 * Three sources go in, and one deliberately does not.
 *
 * | Existing state | Action |
 * | --- | --- |
 * | `marketing_subscriptions.status = 'bounced'` | → `hard_bounce`, global |
 * | `marketing_subscriptions.status = 'complained'` | → `complaint`, that row's own brand |
 * | `leadhub_contacts.do_not_contact = true` | → `manual`, that contact's brand |
 * | `marketing_subscriptions.status = 'unsubscribed'` | **left alone** |
 *
 * The last row is the important one. A per-list unsubscribe is a scoped
 * withdrawal of consent for that list, and `marketing.unsubscribe.global_opt_out`
 * already defaults to `false` — the decision that a list unsubscribe is *not* a
 * global opt-out has been taken deliberately. Promoting those rows here would
 * silently reverse it and destroy legitimate subscriptions on other lists of the
 * same brand, in a migration nobody read.
 *
 * Idempotent: `updateOrInsert` on `(brand_id, email_normalized)`, so running it
 * twice changes nothing. It never overwrites a suppression that is already there
 * — a live one carries a reason somebody chose, and a released one carries a
 * decision somebody made and signed.
 *
 * `down()` removes only what it wrote (`source in ('backfill', 'leadhub')`), so
 * a rollback cannot take a real bounce with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A host can install this addon without the suppression package having
        // migrated yet, or at all. Backfilling into a table that is not there is
        // a crash, and a crash here would leave the install half-migrated.
        if (! Schema::hasTable('suppressions')) {
            return;
        }

        $this->backfillSubscriptions('bounced', 'hard_bounce', global: true);
        $this->backfillSubscriptions('complained', 'complaint', global: false);
        $this->backfillDoNotContact();
    }

    public function down(): void
    {
        if (! Schema::hasTable('suppressions')) {
            return;
        }

        DB::table('suppressions')->whereIn('source', ['backfill', 'leadhub'])->delete();

        if (Schema::hasTable('suppression_events')) {
            DB::table('suppression_events')->whereIn('source', ['backfill', 'leadhub'])->delete();
        }
    }

    protected function backfillSubscriptions(string $status, string $reason, bool $global): void
    {
        if (! Schema::hasTable('marketing_subscriptions')) {
            return;
        }

        $hasBrand = Schema::hasColumn('marketing_subscriptions', 'brand_id');

        DB::table('marketing_subscriptions')
            ->where('status', $status)
            ->whereNotNull('email_normalized')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($reason, $global, $hasBrand): void {
                foreach ($rows as $row) {
                    $this->write(
                        (string) $row->email_normalized,
                        $reason,
                        $global ? 0 : (int) ($hasBrand ? ($row->brand_id ?? 0) : 0),
                        'backfill',
                    );
                }
            });
    }

    protected function backfillDoNotContact(): void
    {
        if (! Schema::hasTable('leadhub_contacts') || ! Schema::hasColumn('leadhub_contacts', 'do_not_contact')) {
            return;
        }

        $hasBrand = Schema::hasColumn('leadhub_contacts', 'brand_id');

        DB::table('leadhub_contacts')
            ->where('do_not_contact', true)
            ->whereNotNull('email_normalized')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($hasBrand): void {
                foreach ($rows as $row) {
                    // Brand-scoped: an editorial block inside one brand's CRM.
                    // LeadHub keeps reading its own flag and the gate keeps
                    // reading both, so this is a copy, not a handover.
                    $this->write(
                        (string) $row->email_normalized,
                        'manual',
                        (int) ($hasBrand ? ($row->brand_id ?? 0) : 0),
                        'leadhub',
                    );
                }
            });
    }

    protected function write(string $email, string $reason, int $brandId, string $source): void
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return;
        }

        $existing = DB::table('suppressions')
            ->where('brand_id', $brandId)
            ->where('email_normalized', $email)
            ->first();

        if ($existing) {
            // Somebody already decided about this address — possibly to release
            // it. A backfill must not overrule that.
            return;
        }

        DB::table('suppressions')->insert([
            'uuid' => (string) Str::uuid(),
            'brand_id' => $brandId,
            'email_normalized' => $email,
            'reason' => $reason,
            'source' => $source,
            'suppressed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! Schema::hasTable('suppression_events')) {
            return;
        }

        DB::table('suppression_events')->insert([
            'uuid' => (string) Str::uuid(),
            'brand_id' => $brandId,
            'email_normalized' => $email,
            'event_type' => 'imported',
            'reason' => $reason,
            'source' => $source,
            // No dedupe key: this is not a provider event, and inventing one
            // would collide with the next legitimate import of the same address.
            'dedupe_key' => null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
