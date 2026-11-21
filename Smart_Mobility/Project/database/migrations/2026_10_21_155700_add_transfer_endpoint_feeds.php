<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('from_feed_id', 32)->nullable()->after('id');
            $table->string('to_feed_id', 32)->nullable()->after('from_stop_id');
            $table->index(['from_feed_id', 'from_stop_id']);
            $table->index(['to_feed_id', 'to_stop_id']);
            $table->unique(
                ['feed_id', 'from_feed_id', 'from_stop_id', 'to_feed_id', 'to_stop_id', 'transfer_type', 'source'],
                'transfers_source_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropIndex(['from_feed_id', 'from_stop_id']);
            $table->dropIndex(['to_feed_id', 'to_stop_id']);
            $table->dropUnique('transfers_source_identity_unique');
            $table->dropColumn(['from_feed_id', 'to_feed_id']);
        });
    }
};
