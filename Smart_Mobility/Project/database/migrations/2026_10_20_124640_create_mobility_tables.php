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
        Schema::create('stops', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32);
            $table->string('stop_id', 128);
            $table->string('name', 255);
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->unsignedTinyInteger('location_type')->default(0);
            $table->string('parent_station', 128)->nullable();
            $table->timestamps();

            $table->unique(['feed_id', 'stop_id']);
            $table->index('stop_id');
            $table->index(['lat', 'lon']);
        });

        Schema::create('routes', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32);
            $table->string('route_id', 128);
            $table->string('short_name', 64)->nullable();
            $table->string('long_name', 255)->nullable();
            $table->unsignedSmallInteger('route_type')->default(3); // 3 = bus, 2 = rail
            $table->string('route_color', 16)->nullable();
            $table->string('route_text_color', 16)->nullable();
            $table->timestamps();

            $table->unique(['feed_id', 'route_id']);
            $table->index('route_id');
        });

        Schema::create('trips', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32);
            $table->string('trip_id', 128);
            $table->string('route_id', 128);
            $table->string('service_id', 128);
            $table->string('trip_headsign', 255)->nullable();
            $table->unsignedTinyInteger('direction_id')->default(0);
            $table->timestamps();

            $table->unique(['feed_id', 'trip_id']);
            $table->index('trip_id');
            $table->index('service_id');
            $table->index('route_id');
        });

        Schema::create('stop_times', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32);
            $table->string('trip_id', 128);
            $table->string('stop_id', 128);
            $table->string('arrival_time', 12);
            $table->string('departure_time', 12);
            $table->unsignedInteger('arrival_seconds');
            $table->unsignedInteger('departure_seconds');
            $table->unsignedInteger('stop_sequence');
            $table->unsignedTinyInteger('pickup_type')->default(0);
            $table->unsignedTinyInteger('drop_off_type')->default(0);
            $table->timestamps();

            $table->index(['stop_id', 'departure_time']);
            $table->index(['trip_id', 'stop_sequence']);
            $table->index(['stop_id', 'departure_seconds']);
            $table->index('departure_seconds');
        });

        Schema::create('calendar', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32);
            $table->string('service_id', 128);
            $table->boolean('monday')->default(false);
            $table->boolean('tuesday')->default(false);
            $table->boolean('wednesday')->default(false);
            $table->boolean('thursday')->default(false);
            $table->boolean('friday')->default(false);
            $table->boolean('saturday')->default(false);
            $table->boolean('sunday')->default(false);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->index(['feed_id', 'service_id']);
            $table->index('service_id');
        });

        Schema::create('calendar_dates', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_id', 32);
            $table->string('service_id', 128);
            $table->date('date');
            $table->unsignedTinyInteger('exception_type')->default(1); // 1 = added, 2 = removed
            $table->timestamps();

            $table->index(['feed_id', 'service_id', 'date']);
            $table->index('date');
        });

        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('from_stop_id', 128);
            $table->string('to_stop_id', 128);
            $table->unsignedTinyInteger('transfer_type')->default(2); // 2 = min time required
            $table->unsignedInteger('min_transfer_time')->default(180); // seconds
            $table->decimal('distance_m', 8, 2)->default(0.00);
            $table->timestamps();

            $table->index(['from_stop_id', 'to_stop_id']);
            $table->index('from_stop_id');
            $table->index('to_stop_id');
        });

        Schema::create('bike_stations', function (Blueprint $table): void {
            $table->id();
            $table->string('station_id', 128)->unique();
            $table->string('name', 255);
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->unsignedSmallInteger('num_bikes_available')->default(0);
            $table->unsignedSmallInteger('num_docks_available')->default(0);
            $table->boolean('is_installed')->default(true);
            $table->boolean('is_renting')->default(true);
            $table->boolean('is_returning')->default(true);
            $table->timestamps();

            $table->index(['lat', 'lon']);
        });

        Schema::create('car_share_stations', function (Blueprint $table): void {
            $table->id();
            $table->string('station_id', 128)->unique();
            $table->string('name', 255);
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->unsignedSmallInteger('num_cars_available')->default(1);
            $table->json('car_types')->nullable();
            $table->timestamps();

            $table->index(['lat', 'lon']);
        });

        Schema::create('car_parks', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->string('near_stop_id', 128)->nullable();
            $table->unsignedSmallInteger('capacity')->default(50);
            $table->boolean('is_park_and_ride')->default(false);
            $table->timestamps();

            $table->index(['lat', 'lon']);
            $table->index('near_stop_id');
        });

        Schema::create('candidate_points', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->boolean('car_accessible')->default(true);
            $table->boolean('has_parking')->default(true);
            $table->boolean('transit_hub')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['lat', 'lon']);
            $table->index('transit_hub');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_points');
        Schema::dropIfExists('car_parks');
        Schema::dropIfExists('car_share_stations');
        Schema::dropIfExists('bike_stations');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('calendar_dates');
        Schema::dropIfExists('calendar');
        Schema::dropIfExists('stop_times');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('stops');
    }
};
