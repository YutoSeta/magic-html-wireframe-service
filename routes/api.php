<?php

use App\Http\Controllers\Api\V1\MaterializeWireframeController;
use App\Http\Controllers\Api\V1\WireframeController;
use App\Http\Controllers\Api\V1\WireframeJobController;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', CapabilityController::class);
Route::get('/__verify', [CapabilityController::class, 'verify']);
Route::get('/health', HealthController::class)->name('health');
Route::middleware(['service', 'throttle:wireframe-requests'])
    ->post('/v1/wireframes', [WireframeController::class, 'store']);
Route::middleware(['service', 'throttle:wireframe-requests'])->group(function (): void {
    Route::post('/v1/wireframe-jobs', [WireframeJobController::class, 'store']);
    Route::get('/v1/wireframe-jobs/{job}', [WireframeJobController::class, 'show']);
});
Route::middleware(['service', 'throttle:wireframe-requests'])
    ->post('/v1/wireframes/materialize', MaterializeWireframeController::class);
