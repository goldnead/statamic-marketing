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
