<?php

use App\Http\Controllers\Api\V1\LayoutSnapshotController;
use App\Http\Controllers\Api\V1\MaterializeWireframeController;
use App\Http\Controllers\Api\V1\WireframeController;
use App\Http\Controllers\Api\V1\WireframeJobController;
use App\Http\Controllers\Api\V1\WireframePresentationController;
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
Route::middleware(['service:layout', 'throttle:wireframe-requests'])->group(function (): void {
    Route::post('/v1/layout-snapshots', [LayoutSnapshotController::class, 'store'])
        ->name('layout-snapshots.store');
    Route::get('/v1/layout-snapshots/{layoutSnapshot}', [LayoutSnapshotController::class, 'show'])
        ->where('layoutSnapshot', 'ls_[a-f0-9]{64}')
        ->name('layout-snapshots.show');
    Route::post('/v1/layout-snapshots/{layoutSnapshot}/freeze', [LayoutSnapshotController::class, 'freeze'])
        ->where('layoutSnapshot', 'ls_[a-f0-9]{64}')
        ->name('layout-snapshots.freeze');
    Route::post('/v1/layout-snapshots/{layoutSnapshot}/patches', [LayoutSnapshotController::class, 'patch'])
        ->where('layoutSnapshot', 'ls_[a-f0-9]{64}')
        ->name('layout-snapshots.patches.store');
    Route::post('/v1/wireframe-presentations', WireframePresentationController::class)
        ->name('wireframe-presentations.store');
});
