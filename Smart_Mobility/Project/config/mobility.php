<?php

return [
    'walk_speed_kmh' => (float) env('MOBILITY_WALK_SPEED_KMH', 4.8),
    'bike_speed_kmh' => (float) env('MOBILITY_BIKE_SPEED_KMH', 15.0),
    'car_speed_urban_kmh' => (float) env('MOBILITY_CAR_SPEED_URBAN_KMH', 35.0),
    'car_speed_interurban_kmh' => (float) env('MOBILITY_CAR_SPEED_INTERURBAN_KMH', 70.0),
    'detour_factor' => (float) env('MOBILITY_DETOUR_FACTOR', 1.3),
    'transfer_buffer_min' => (int) env('MOBILITY_TRANSFER_BUFFER_MIN', 3),
    'parking_time_min' => (int) env('MOBILITY_PARKING_TIME_MIN', 7),
    'walk_transfer_radius_m' => (int) env('MOBILITY_WALK_TRANSFER_RADIUS_M', 400),
    'max_walk_to_pickup_m' => (int) env('MOBILITY_MAX_WALK_TO_PICKUP_M', 800),
    'max_detour_min' => (int) env('MOBILITY_MAX_DETOUR_MIN', 15),
    'max_wait_min' => (int) env('MOBILITY_MAX_WAIT_MIN', 10),
    'top_n_results' => (int) env('MOBILITY_TOP_N_RESULTS', 3),

    'gbfs' => [
        'bike_gbfs_url' => env('OPTYMO_GBFS_BIKE_URL', 'https://data.optymo.fr/vls/gbfs.json'),
        'car_gbfs_url' => env('OPTYMO_GBFS_CAR_URL', 'https://data.optymo.fr/als/gbfs.json'),
        'bike_station_information_url' => env('OPTYMO_GBFS_BIKE_INFO_URL', 'https://data.optymo.fr/vls/station_information.json'),
        'bike_station_status_url' => env('OPTYMO_GBFS_BIKE_STATUS_URL', 'https://data.optymo.fr/vls/station_status.json'),
        'car_station_information_url' => env('OPTYMO_GBFS_CAR_INFO_URL', 'https://data.optymo.fr/als/station_information.json'),
        'car_station_status_url' => env('OPTYMO_GBFS_CAR_STATUS_URL', 'https://data.optymo.fr/als/station_status.json'),
        'status_cache_ttl' => (int) env('OPTYMO_GBFS_STATUS_CACHE_TTL', 60),
    ],

    'bounding_box' => [
        'min_lat' => 47.45,
        'max_lat' => 47.72,
        'min_lon' => 6.72,
        'max_lon' => 6.96,
    ],

    'data_path' => storage_path('app/data'),
];
