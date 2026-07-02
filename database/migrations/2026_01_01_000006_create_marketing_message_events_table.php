<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_message_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('marketing_messages')->cascadeOnDelete();
            $table->string('type')->index();
            $table->text('url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_message_events');
    }
};
