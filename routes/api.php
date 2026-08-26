<?php

use App\Http\Controllers\Api\V1\WireframeController;
use App\Http\Controllers\CapabilityController;
use Illuminate\Support\Facades\Route;

Route::get('/', CapabilityController::class);
Route::get('/__verify', [CapabilityController::class, 'verify']);
Route::middleware(['service', 'throttle:wireframe-requests'])
    ->post('/v1/wireframes', [WireframeController::class, 'store']);
