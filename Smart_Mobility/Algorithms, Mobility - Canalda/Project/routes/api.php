<?php

use App\Http\Controllers\MapController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\StopSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/bootstrap', [MapController::class, 'bootstrap']);
Route::post('/plan', [PlanController::class, 'plan']);
Route::get('/plan/{runId}/progress', [PlanController::class, 'progress']);
Route::get('/stops/search', StopSearchController::class);
