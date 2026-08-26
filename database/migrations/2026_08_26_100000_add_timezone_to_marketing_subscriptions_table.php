<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the recipient is, so a send window means their morning and not the
 * server's.
 *
 * Nullable and never guessed. A wrong timezone does not fail — it delivers at
 * the wrong hour, and nothing in the data afterwards says which answer was
 * used. Absent means "fall through to the list's, then the application's",
 * which is what every installation does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_subscriptions', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
