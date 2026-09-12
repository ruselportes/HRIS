<?php

use App\Http\Controllers\Api\AttendanceSyncController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CrewController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ForemanController;
use App\Http\Controllers\Api\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth (public)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
});

/*
|--------------------------------------------------------------------------
| Authenticated API
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::get('roles', [ReferenceController::class, 'roles']);
    Route::get('sites', [ReferenceController::class, 'sites']);

    // Crew builder (UC-03). Writes are engineer-only via CrewPolicy; the role
    // middleware is defense-in-depth. {crew} binds Crew for show/update.
    Route::prefix('crews')->middleware('role:hr,engineer,executive')->group(function () {
        Route::get('/', [CrewController::class, 'index']);

        // Must precede apiResource-style {crew} routes so 'pool' isn't bound.
        Route::get('pool', [CrewController::class, 'pool']);

        Route::post('/', [CrewController::class, 'store'])->middleware('role:engineer');
        Route::get('{crew}', [CrewController::class, 'show']);
        Route::put('{crew}', [CrewController::class, 'update'])->middleware('role:engineer');
        Route::put('{crew}/foreman', [CrewController::class, 'foreman'])->middleware('role:engineer');
        Route::post('{crew}/members', [CrewController::class, 'assignMembers'])->middleware('role:engineer');
        Route::post('{crew}/deploy', [CrewController::class, 'deploy'])->middleware('role:engineer');
        Route::delete('{crew}/members/{employee}', [CrewController::class, 'removeMember'])->middleware('role:engineer');
    });

    Route::get('deployment', [CrewController::class, 'deployment'])->middleware('role:hr,engineer,executive');

    // Mobile roster fetch (UC-04) — foreman's own deployed crew only.
    Route::get('me/crew', [ForemanController::class, 'myCrew'])->middleware('role:foreman');

    // Device binding (Phase 5) — the trust anchor for the integrity engine.
    // Foreman-only, and every handler scopes to the authenticated employee so
    // a device id from the request can never reach someone else's device.
    Route::prefix('me/devices')->middleware('role:foreman')->group(function () {
        Route::get('/', [DeviceController::class, 'index']);
        Route::post('/', [DeviceController::class, 'bind']);
        Route::delete('{deviceId}', [DeviceController::class, 'revoke']);
    });

    // Attendance sync ingestion (Phase 5 layer 4). Returns 207 when any event
    // in the batch was rejected, so a partially accepted batch is not
    // indistinguishable from a fully accepted one.
    Route::post('attendance/sync', [AttendanceSyncController::class, 'store'])
        ->middleware('role:foreman');

    // Must precede apiResource so 'next-code' isn't captured as {employee}.
    Route::middleware('role:hr,admin')->get('employees/next-code', [EmployeeController::class, 'nextCode']);

    Route::apiResource('employees', EmployeeController::class)->except('destroy');
});
