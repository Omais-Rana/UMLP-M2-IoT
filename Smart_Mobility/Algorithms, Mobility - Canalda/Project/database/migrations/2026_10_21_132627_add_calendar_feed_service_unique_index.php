<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calendar', function (Blueprint $table) {
            $table->unique(['feed_id', 'service_id'], 'calendar_feed_service_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar', function (Blueprint $table) {
            $table->dropUnique('calendar_feed_service_unique');
        });
    }
};
