<?php

use App\Http\Controllers\Api\V1\WireframeController;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', CapabilityController::class);
Route::get('/__verify', [CapabilityController::class, 'verify']);
Route::get('/health', HealthController::class)->name('health');
Route::middleware(['service', 'throttle:wireframe-requests'])
    ->post('/v1/wireframes', [WireframeController::class, 'store']);
