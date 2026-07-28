<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('list_handle')->index();
            $table->string('email');
            $table->string('email_normalized')->index();
            // SHA-256 of (list_handle, normalized email); see
            // Subscription::uniquenessKeyFor(). The consent unique is built on
            // this fixed-width column instead of on the two wide varchars,
            // which together cost 2040 of InnoDB's 3072 index bytes under
            // utf8mb4. NOT NULL on purpose: a unique does not constrain NULL,
            // so a nullable column here would be an index that enforces
            // nothing for exactly the rows it exists for.
            $table->string('uniqueness_key', 64);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->uuid('contact_uuid')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->string('token', 64)->unique();
            $table->string('source')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['list_handle', 'email_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_subscriptions');
    }
};
