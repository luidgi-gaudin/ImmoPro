<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailOtpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GuarantorController;
use App\Http\Controllers\LeaseController;
use App\Http\Controllers\LeasePhotoController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\RentPaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantSpaceController;
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

    /*
     * Vérification de l'adresse par code à usage unique.
     *
     * Nécessairement anonymes : celui qui vérifie son adresse n'a pas encore de
     * jeton, c'est justement ce qu'il vient chercher.
     *
     * Les plafonds sont nommés plutôt qu'écrits en clair sur la route. Un
     * `throttle:5,1` inline compte par domaine et par IP, sans distinguer les
     * routes : l'inscription consommerait le quota du code qui la suit. Voir
     * AppServiceProvider::registerRateLimiters().
     */
    Route::post('/otp/send', [EmailOtpController::class, 'send'])
        ->middleware('throttle:otp-send')
        ->name('otp.send');

    Route::post('/otp/verify', [EmailOtpController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('otp.verify');

    /*
     * Inscription et connexion par Google ou Apple.
     *
     * La liste des fournisseurs est publique et non plafonnée : elle ne rend
     * que des identifiants clients, que le navigateur devra de toute façon
     * présenter au fournisseur.
     */
    Route::get('/providers', [SocialAuthController::class, 'providers'])->name('providers');

    Route::middleware('throttle:10,1')->post(
        '/social/{provider}',
        [SocialAuthController::class, 'callback']
    )->name('social.callback');

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

        // Rattachement d'un fournisseur à un compte déjà ouvert.
        Route::post('/social/{provider}/link', [SocialAuthController::class, 'link'])->name('social.link');
        Route::delete('/social/{provider}', [SocialAuthController::class, 'unlink'])->name('social.unlink');

        /*
         * Informations personnelles.
         *
         * Le changement d'adresse suit son propre parcours, en deux temps :
         * l'ancienne reste celle du compte tant que la nouvelle n'a pas reçu
         * son code.
         */
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::middleware('throttle:5,1')->group(function () {
            Route::post('/profile/email', [ProfileController::class, 'requestEmailChange'])
                ->name('profile.email.request');
            Route::post('/profile/email/confirm', [ProfileController::class, 'confirmEmailChange'])
                ->name('profile.email.confirm');
        });

        Route::delete('/profile/email', [ProfileController::class, 'cancelEmailChange'])
            ->name('profile.email.cancel');

        Route::get('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar.upload');
        Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.delete');

        // Préférences de notification, par sujet et par canal.
        Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show'])
            ->name('notification-preferences.show');
        Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update'])
            ->name('notification-preferences.update');
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

    /*
     * Catalogue des valeurs fermées (types de bien, garanties, catégories de
     * document...). Une seule source, pour que le front n'ait pas à recopier
     * des listes qui finiraient par diverger.
     */
    Route::get('/reference', [ReferenceController::class, 'index'])
        ->name('reference')
        ->withoutMiddleware('auth:sanctum');

    Route::apiResource('portfolios', PortfolioController::class);
    Route::apiResource('portfolios.properties', PropertyController::class)->scoped();

    // Archivage d'un dossier locataire, distinct de la suppression : un dossier
    // clos sort des listes de travail sans quitter la base, la prescription des
    // loyers courant sur trois ans.
    Route::post('/tenants/{tenant}/archive', [TenantController::class, 'archive'])->name('tenants.archive');
    Route::delete('/tenants/{tenant}/archive', [TenantController::class, 'unarchive'])->name('tenants.unarchive');

    // Invitation du locataire à ouvrir son espace.
    Route::post('/tenants/{tenant}/invite', [TenantController::class, 'invite'])
        ->middleware('throttle:10,1')
        ->name('tenants.invite');

    Route::apiResource('tenants', TenantController::class);

    // Garants, toujours imbriqués sous le dossier auquel ils se rattachent.
    Route::apiResource('tenants.guarantors', GuarantorController::class)->scoped();

    Route::post('/leases/{lease}/terminate', [LeaseController::class, 'terminate'])->name('leases.terminate');
    Route::post('/leases/{lease}/revise-rent', [LeaseController::class, 'reviseRent'])->name('leases.revise-rent');
    Route::apiResource('leases', LeaseController::class);

    /*
     * Échéancier de loyer.
     *
     * Ces routes sont déclarées avant la ressource : « generate » et
     * « bulk-pay » seraient sinon captées comme des identifiants d'échéance par
     * `payments/{payment}`, qui vient ensuite.
     *
     * La génération remplace la saisie mois par mois — un bail de trois ans,
     * c'est trente-six formulaires identiques à la date près. Le pointage, lui,
     * est le geste quotidien : constater qu'un virement est arrivé ne doit pas
     * passer par le formulaire complet de l'échéance.
     */
    Route::post('/leases/{lease}/payments/generate', [RentPaymentController::class, 'generate'])
        ->name('leases.payments.generate');
    Route::post('/leases/{lease}/payments/bulk-pay', [RentPaymentController::class, 'bulkPay'])
        ->name('leases.payments.bulk-pay');
    Route::post('/leases/{lease}/payments/{payment}/pay', [RentPaymentController::class, 'pay'])
        ->scopeBindings()
        ->name('leases.payments.pay');
    Route::post('/leases/{lease}/payments/{payment}/unpay', [RentPaymentController::class, 'unpay'])
        ->scopeBindings()
        ->name('leases.payments.unpay');

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

/*
 * Espace locataire.
 *
 * Le point de vue s'inverse : ces routes partent des dossiers rattachés au
 * compte connecté, pas d'un parc de biens. `role:locataire` ne protège pas les
 * données — l'isolation reste l'affaire des policies et de la Row Level
 * Security — il évite qu'un bailleur atterrisse sur des écrans qui, pour lui,
 * n'afficheraient rien.
 */
Route::middleware(['auth:sanctum', 'role:locataire'])
    ->prefix('tenant-space')
    ->name('tenant-space.')
    ->group(function () {
        Route::get('/', [TenantSpaceController::class, 'overview'])->name('overview');
        Route::get('/leases/{lease}', [TenantSpaceController::class, 'lease'])
            ->where('lease', '[0-9]+')
            ->name('leases.show');

        Route::get('/documents/categories', [TenantSpaceController::class, 'documentCategories'])
            ->name('documents.categories');
        Route::get('/documents', [TenantSpaceController::class, 'documents'])->name('documents.index');
        Route::post('/documents', [TenantSpaceController::class, 'storeDocument'])->name('documents.store');
        Route::get('/documents/{document}/download', [TenantSpaceController::class, 'downloadDocument'])
            ->where('document', '[0-9]+')
            ->name('documents.download');
    });
