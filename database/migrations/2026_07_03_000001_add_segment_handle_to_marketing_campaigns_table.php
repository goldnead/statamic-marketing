<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->string('segment_handle')->nullable()->index()->after('list_handle');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            // Drop the index before the column: SQLite errors when dropping a
            // column that still backs an index.
            $table->dropIndex(['segment_handle']);
            $table->dropColumn('segment_handle');
        });
    }
};
