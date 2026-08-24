<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\LeaseController;
use App\Http\Controllers\LeasePhotoController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\RentPaymentController;
use App\Http\Controllers\ReportController;
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

        // « Rester connecté » depuis l'avertissement d'expiration.
        Route::post('/session/extend', [AuthController::class, 'extendSession'])->name('session.extend');
        Route::put('/password', [PasswordResetController::class, 'update'])->name('password.update');

        Route::post('/2fa/enable', [TwoFactorController::class, 'enable'])->name('2fa.enable');
        Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('2fa.confirm');
        Route::post('/2fa/disable', [TwoFactorController::class, 'disable'])->name('2fa.disable');
        Route::post('/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('2fa.recovery-codes');
    });
});

/*
 * Aperçu d'une pièce jointe, hors authentification par jeton.
 *
 * Une balise <img> ou <iframe> ne peut pas porter d'en-tête d'autorisation :
 * c'est la signature de l'URL qui fait office d'autorisation. Elle couvre
 * l'identifiant du document, celui du bailleur et l'horodatage d'expiration —
 * modifier l'un d'eux invalide le lien.
 *
 * Le débit est plafonné : ces URL circulent hors du jeton, et rien n'empêche
 * d'en rejouer une pendant sa fenêtre de validité.
 */
Route::get('/documents/{documentId}/preview', [DocumentController::class, 'preview'])
    ->where('documentId', '[0-9]+')
    ->middleware(['signed', 'throttle:60,1'])
    ->name('documents.preview');

Route::middleware('auth:sanctum')->group(function () {
    // Recherche globale. Le throttle n'est pas décoratif : chaque appel balaie
    // cinq tables avec un LIKE non indexable, et la barre interroge l'API à
    // chaque frappe. Sans plafond, un onglet laissé ouvert suffit à saturer la
    // base distante. 60 requêtes par minute laissent une frappe confortable.
    Route::get('/search', [GlobalSearchController::class, 'search'])
        ->middleware('throttle:60,1')
        ->name('search');

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

    // Photos de l'état des lieux d'entrée / sortie d'un bail.
    Route::post('/leases/{lease}/photos', [LeasePhotoController::class, 'store'])->name('leases.photos.store');
    Route::delete('/leases/{lease}/photos/{photo}', [LeasePhotoController::class, 'destroy'])
        ->scopeBindings()
        ->name('leases.photos.destroy');

    /*
     * Pièces jointes : bail signé, état des lieux, DPE, assurance, pièce
     * d'identité. Le catalogue des catégories est déclaré avant la ressource,
     * pour qu'il ne soit pas capté comme un identifiant de document.
     */
    Route::get('/documents/categories', [DocumentController::class, 'categories'])->name('documents.categories');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::apiResource('documents', DocumentController::class)->only(['index', 'store', 'update', 'destroy']);

    // Tout le tableau de bord en un appel, plutôt que cinq.
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Rapports agrégés (bailleur, biens, locataires).
    Route::get('/reports/overview', [ReportController::class, 'overview'])->name('reports.overview');

    // Alertes proactives (impayés, révision IRL, fin de bail, expiration DPE).
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/read-all', [AlertController::class, 'markAllAsRead'])->name('alerts.read-all');
    Route::post('/alerts/{alert}/read', [AlertController::class, 'markAsRead'])->name('alerts.read');
    Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');
    Route::post('/alerts/{alert}/remind', [AlertController::class, 'remind'])->name('alerts.remind');
});
