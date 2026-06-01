<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\LeaseController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])->name('login');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/user', [AuthController::class, 'user'])->name('user');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('portfolios', PortfolioController::class);
    Route::apiResource('portfolios.properties', PropertyController::class)->scoped();
    Route::apiResource('tenants', TenantController::class);
    Route::apiResource('leases', LeaseController::class);
});
