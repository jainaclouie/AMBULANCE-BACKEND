<?php

use App\Http\Controllers\Api\AdminAmbulanceController;
use App\Http\Controllers\Api\AdminHospitalController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CrewController;
use App\Http\Controllers\Api\FleetController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/barangays', [LookupController::class, 'barangays']);
    Route::get('/hospitals', [LookupController::class, 'hospitals']);

    // In-app notification bell
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);

    // Phone notifications (Web Push)
    Route::get('/push/public-key', [PushController::class, 'publicKey']);
    Route::post('/push/subscribe', [PushController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);

    // Dispatcher + admin: live map, recommendations, incidents
    Route::middleware('role:admin,dispatcher')->group(function () {
        Route::get('/fleet', [FleetController::class, 'index']);
        Route::post('/recommendations', [FleetController::class, 'recommend']);
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::post('/incidents', [IncidentController::class, 'store']);
        Route::get('/incidents/{incident}', [IncidentController::class, 'show']);
        Route::post('/incidents/{incident}/assign', [IncidentController::class, 'assign']);
        Route::post('/incidents/{incident}/cancel', [IncidentController::class, 'cancel']);
    });

    // Admin: master data
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::apiResource('ambulances', AdminAmbulanceController::class);
        Route::apiResource('users', AdminUserController::class);
        Route::apiResource('hospitals', AdminHospitalController::class)->except(['show']);
    });

    // Ambulance crew app
    Route::middleware('role:crew')->prefix('crew')->group(function () {
        Route::get('/state', [CrewController::class, 'state']);
        Route::post('/duty', [CrewController::class, 'duty']);
        Route::post('/location', [CrewController::class, 'location']);
        Route::post('/dispatches/{dispatch}/accept', [CrewController::class, 'accept']);
        Route::post('/dispatches/{dispatch}/decline', [CrewController::class, 'decline']);
        Route::post('/dispatches/{dispatch}/advance', [CrewController::class, 'advance']);
    });
});
