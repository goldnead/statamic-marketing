<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the send guard from a question into a claim.
 *
 * `SendMessageJob` used to open with `if ($message->status !== 'pending')
 * return;` — a read, followed by a send, followed by a write. Two workers both
 * read `pending`, both pass, both send. Reproduced: one message row, two mails
 * at the same address, and the row afterwards reads `sent` exactly once, so
 * nothing in the data says it happened.
 *
 * Two ordinary situations produce it. The scheduler starts
 * `marketing:send-scheduled` every minute, so a run lasting longer than a
 * minute overlaps its successor; and any worker killed between the send and
 * the status write leaves the row `pending` for the retry to pick up and send
 * again. The second needs no concurrency at all — one worker and bad timing.
 *
 * `claimed_at` is the lease that makes the claim safe to hold. Without it a
 * worker dying *after* claiming would strand the message in `sending` with
 * nothing to pick it up, which trades a duplicate mail for a missing one. The
 * index is the sweeper's, which asks exactly this pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable()->after('status');
            $table->index(['status', 'claimed_at'], 'marketing_messages_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table): void {
            $table->dropIndex('marketing_messages_claim_idx');
            $table->dropColumn('claimed_at');
        });
    }
};
