<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClinicalController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\GuestController;
use App\Http\Controllers\Api\V1\HealthRecordSyncController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PatientRecordController;
use App\Http\Controllers\Api\V1\StudyController;
use App\Http\Controllers\Api\V1\WoundScanSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // --- Public ---------------------------------------------------------
    // Throttled: these are the only unauthenticated write endpoints, so they are
    // the natural target for credential stuffing.
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);

        // Anonymous participation, keyed to the app-generated device_uuid.
        // Idempotent, so relaunching resumes the same guest rather than
        // fragmenting one participant across several anonymous records.
        Route::post('auth/guest', [GuestController::class, 'session']);

        // OTP password reset (replaces the Firebase Cloud Function).
        Route::post('auth/forgot-password', [PasswordResetController::class, 'request']);
        Route::post('auth/reset-password', [PasswordResetController::class, 'reset']);
    });

    // --- Model bundle (public) -------------------------------------------
    // A phone choosing Offline mode at first launch has not necessarily signed
    // in yet, and these are the same files shipped in every APK.
    Route::get('models/manifest', [ModelController::class, 'manifest']);
    Route::get('models/file/{name}', [ModelController::class, 'file'])
        ->where('name', '[A-Za-z0-9._-]+');

    // --- Authenticated --------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        // Upgrade an anonymous session in place, carrying its history over.
        Route::post('auth/claim', [GuestController::class, 'claim']);
        Route::post('auth/password', [AuthController::class, 'updatePassword']);
        Route::post('auth/profile', [AuthController::class, 'updateProfile']);

        // Online-mode analysis. Authenticated: it spends real CPU on the
        // server, and the image is a patient's photograph.
        Route::post('analyse', [AnalysisController::class, 'analyse'])
            ->middleware('throttle:20,1');

        Route::post('devices/register', [DeviceController::class, 'register']);
        Route::patch('devices/{uuid}/mode', [DeviceController::class, 'updateMode']);
        Route::get('devices', [DeviceController::class, 'index']);

        // The sync endpoint gets its own, looser throttle: a device returning from a
        // long offline stretch legitimately sends several batches back to back.
        Route::post('wound-scans/sync', [WoundScanSyncController::class, 'sync'])
            ->middleware('throttle:30,1');

        Route::get('wound-scans', [WoundScanSyncController::class, 'index']);

        // The photograph uploads separately from the record: one multipart
        // request per image, keyed by the same local_uuid the batch sync uses.
        Route::post('wound-scans/{localUuid}/image', [WoundScanSyncController::class, 'storeImage']);

        // Images live on the private disk, so this is the only way to read one.
        // Authorisation is checked inside the controller.
        Route::get('wound-scans/{localUuid}/image', [WoundScanSyncController::class, 'image']);
        Route::get('wound-scans/{localUuid}/overlay', [WoundScanSyncController::class, 'overlay']);

        // Everything else the app records: glucose, medications, medication-logs,
        // self-care, qol, satisfaction, appointments, sus, engagement, consents.
        // One route, one idempotent code path — see HealthRecordSyncController.
        // 60/min proved too tight in practice: a device with a backlog of
        // analytics events sends 50-row batches and rate-limited itself while
        // draining legitimately. These routes are authenticated and per-user, so
        // the limit is about protecting the server from a runaway client, not
        // about abuse.
        Route::post('sync/{type}', [HealthRecordSyncController::class, 'sync'])
            ->middleware('throttle:240,1');

        // --- Clinician only ---------------------------------------------
        Route::middleware('clinician')->group(function () {
            Route::get('dashboard/stats', [DashboardController::class, 'stats']);
            Route::get('dashboard/trends', [DashboardController::class, 'trends']);
            Route::get('patients', [PatientController::class, 'index']);
            Route::get('patients/{patient}', [PatientController::class, 'show']);
            // Full clinical record for one patient, assembled in a single payload.
            Route::get('patients/{patient}/record', [PatientRecordController::class, 'show']);
            Route::get('study/summary', [StudyController::class, 'summary']);

            // Cross-patient clinical work queues.
            Route::get('alerts', [ClinicalController::class, 'alerts']);
            Route::get('appointments', [ClinicalController::class, 'appointments']);
            Route::get('medications', [ClinicalController::class, 'medications']);

            // Fleet + sync health.
            Route::get('devices/{uuid}/detail', [OperationsController::class, 'device']);
            Route::get('sync-monitor', [OperationsController::class, 'syncMonitor']);

            // CSV export of study data.
            Route::get('export/{type}', [ExportController::class, 'download']);

            // User administration is admin-only, not merely clinician-only:
            // granting roles is a different privilege from reading charts.
            Route::middleware('admin')->group(function () {
                Route::get('users', [OperationsController::class, 'users']);
                Route::post('users', [OperationsController::class, 'createUser']);
                Route::patch('users/{user}/role', [OperationsController::class, 'updateRole']);
            });
        });
    });
});
