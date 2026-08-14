<?php

use Goldnead\Marketing\Support\MachineOpen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was an open a person, or a machine loading the image for them?
 *
 * Apple's Mail Privacy Protection fetches the tracking pixel for every message
 * it delivers, shortly after delivery, whether anybody looks or not — and then
 * caches it, so the reading that follows leaves no trace at all. Without this
 * column a timeline says "geöffnet" about a mail nobody opened and stays silent
 * about the one they did.
 *
 * A boolean and nothing else. The verdict is computed while the request is in
 * hand ({@see MachineOpen}); the user agent it was
 * read from is not stored, and neither is the IP. An answer is kept, not the
 * material it was derived from.
 *
 * Existing rows default to `false` — "as far as anyone knew, a person". That is
 * the same thing the reports said about them yesterday, so no number moves
 * under anybody's feet on the day this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_message_events', function (Blueprint $table) {
            $table->boolean('machine')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_message_events', function (Blueprint $table) {
            $table->dropColumn('machine');
        });
    }
};
