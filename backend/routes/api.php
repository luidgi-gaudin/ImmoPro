<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\LeaseController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\RentPaymentController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])->name('login');
        Route::post('/2fa/challenge', [TwoFactorController::class, 'challenge'])->name('2fa.challenge');
    });

    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/user', [AuthController::class, 'user'])->name('user');
        Route::put('/password', [PasswordResetController::class, 'update'])->name('password.update');

        Route::post('/2fa/enable', [TwoFactorController::class, 'enable'])->name('2fa.enable');
        Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('2fa.confirm');
        Route::post('/2fa/disable', [TwoFactorController::class, 'disable'])->name('2fa.disable');
        Route::post('/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('2fa.recovery-codes');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('portfolios', PortfolioController::class);
    Route::apiResource('portfolios.properties', PropertyController::class)->scoped();
    Route::apiResource('tenants', TenantController::class);

    Route::post('/leases/{lease}/terminate', [LeaseController::class, 'terminate'])->name('leases.terminate');
    Route::post('/leases/{lease}/revise-rent', [LeaseController::class, 'reviseRent'])->name('leases.revise-rent');
    Route::apiResource('leases', LeaseController::class);

    Route::get('/leases/{lease}/payments/{payment}/quittance', [RentPaymentController::class, 'quittance'])
        ->scopeBindings()
        ->name('leases.payments.quittance');
    Route::apiResource('leases.payments', RentPaymentController::class)->scoped();

    // Alertes proactives (impayés, révision IRL, fin de bail, expiration DPE).
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/read-all', [AlertController::class, 'markAllAsRead'])->name('alerts.read-all');
    Route::post('/alerts/{alert}/read', [AlertController::class, 'markAsRead'])->name('alerts.read');
    Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');
    Route::post('/alerts/{alert}/remind', [AlertController::class, 'remind'])->name('alerts.remind');
});
