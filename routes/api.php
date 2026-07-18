<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\WoundScanSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // --- Public ---------------------------------------------------------
    // Throttled: these are the only unauthenticated write endpoints, so they are
    // the natural target for credential stuffing.
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
    });

    // --- Authenticated --------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::post('devices/register', [DeviceController::class, 'register']);
        Route::patch('devices/{uuid}/mode', [DeviceController::class, 'updateMode']);
        Route::get('devices', [DeviceController::class, 'index']);

        // The sync endpoint gets its own, looser throttle: a device returning from a
        // long offline stretch legitimately sends several batches back to back.
        Route::post('wound-scans/sync', [WoundScanSyncController::class, 'sync'])
            ->middleware('throttle:30,1');

        Route::get('wound-scans', [WoundScanSyncController::class, 'index']);

        // --- Clinician only ---------------------------------------------
        Route::middleware('clinician')->group(function () {
            Route::get('dashboard/stats', [DashboardController::class, 'stats']);
            Route::get('patients', [PatientController::class, 'index']);
            Route::get('patients/{patient}', [PatientController::class, 'show']);
        });
    });
});
