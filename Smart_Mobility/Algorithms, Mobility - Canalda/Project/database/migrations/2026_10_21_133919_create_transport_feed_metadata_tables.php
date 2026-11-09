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
        Schema::create('transport_feeds', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32)->unique();
            $table->string('feed_name', 128);
            $table->string('publisher_name', 255)->nullable();
            $table->string('publisher_url', 2048)->nullable();
            $table->string('version', 255)->nullable();
            $table->date('feed_start_date')->nullable();
            $table->date('feed_end_date')->nullable();
            $table->string('source_type', 32)->default('bundled');
            $table->string('encoding', 32)->default('UTF-8');
            $table->unsignedInteger('agency_count')->default(0);
            $table->unsignedInteger('stop_count')->default(0);
            $table->unsignedInteger('route_count')->default(0);
            $table->unsignedInteger('trip_count')->default(0);
            $table->unsignedInteger('stop_time_count')->default(0);
            $table->unsignedInteger('shape_point_count')->default(0);
            $table->unsignedInteger('transfer_count')->default(0);
            $table->json('warnings')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agencies', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32);
            $table->string('agency_id', 128);
            $table->string('name', 255);
            $table->string('url', 2048)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('fare_url', 2048)->nullable();
            $table->string('email', 255)->nullable();
            $table->timestamps();

            $table->unique(['feed_id', 'agency_id']);
            $table->index('feed_id');
        });

        Schema::create('shapes', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32);
            $table->string('shape_id', 128);
            $table->unsignedInteger('shape_pt_sequence');
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->decimal('distance_traveled', 12, 3)->nullable();
            $table->timestamps();

            $table->unique(['feed_id', 'shape_id', 'shape_pt_sequence']);
            $table->index(['feed_id', 'shape_id', 'shape_pt_sequence']);
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('feed_id', 32)->nullable()->after('id');
            $table->string('source', 32)->default('generated')->after('transfer_type');
            $table->index(['feed_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropIndex(['feed_id', 'source']);
            $table->dropColumn(['feed_id', 'source']);
        });
        Schema::dropIfExists('shapes');
        Schema::dropIfExists('agencies');
        Schema::dropIfExists('transport_feeds');
    }
};
