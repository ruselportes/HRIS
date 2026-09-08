<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
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

    // Must precede apiResource so 'next-code' isn't captured as {employee}.
    Route::middleware('role:hr,admin')->get('employees/next-code', [EmployeeController::class, 'nextCode']);

    Route::apiResource('employees', EmployeeController::class)->except('destroy');
});
