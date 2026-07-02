<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_lists', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('double_opt_in')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_lists');
    }
};
