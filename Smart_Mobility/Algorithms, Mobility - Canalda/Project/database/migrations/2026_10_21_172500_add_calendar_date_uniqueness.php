<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('calendar_dates')
            ->select('feed_id', 'service_id', 'date')
            ->groupBy('feed_id', 'service_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $keepId = DB::table('calendar_dates')
                ->where('feed_id', $duplicate->feed_id)
                ->where('service_id', $duplicate->service_id)
                ->where('date', $duplicate->date)
                ->orderByDesc('id')
                ->value('id');

            DB::table('calendar_dates')
                ->where('feed_id', $duplicate->feed_id)
                ->where('service_id', $duplicate->service_id)
                ->where('date', $duplicate->date)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('calendar_dates', function (Blueprint $table): void {
            $table->unique(
                ['feed_id', 'service_id', 'date'],
                'calendar_dates_feed_service_date_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('calendar_dates', function (Blueprint $table): void {
            $table->dropUnique('calendar_dates_feed_service_date_unique');
        });
    }
};
