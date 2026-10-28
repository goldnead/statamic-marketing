<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The claim on an A/B winner decision.
 *
 * One row per campaign, and the unique `claim_key` is what makes "who decides"
 * an atomic question on every host and with every campaign storage driver
 * (the flat files have no compare-and-swap, a cache lock only holds where the
 * cache is shared and real). The first insert wins; every later one fails on
 * the index and reads the winner's row instead.
 *
 * `claim_key` is `<brand id>|<campaign handle>`: handles are only unique per
 * brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_ab_decisions')) {
            return;
        }

        Schema::create('marketing_ab_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('claim_key')->unique();
            $table->string('campaign_handle');
            $table->string('winner', 8);
            $table->string('reason', 32);
            $table->json('results')->nullable();
            $table->timestamp('decided_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_ab_decisions');
    }
};
