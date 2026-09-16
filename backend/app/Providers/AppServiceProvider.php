<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Notre modèle résout le jeton, son porteur et l'identité RLS en une
        // seule requête, et n'écrit `last_used_at` que toutes les cinq minutes.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Le lien de réinitialisation pointe vers le front (SPA), pas vers l'API.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')
                .'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        $this->registerRateLimiters();
    }

    /**
     * Plafonds nommés pour les codes à usage unique.
     *
     * Un `throttle:5,1` posé directement sur la route ne fait pas ce qu'on
     * croit : sur une requête anonyme, Laravel construit sa clé de comptage à
     * partir du domaine et de l'adresse IP, sans le nom de la route. Toutes les
     * routes publiques d'un même domaine partagent donc **un seul** compteur.
     * Une inscription consomme le quota du code qui la suit immédiatement, et
     * quelques essais de connexion suffisent à interdire la vérification à
     * quelqu'un qui n'a rien demandé.
     *
     * Ces limiteurs comptent par adresse e-mail **et** par IP, ce qui vaut
     * mieux dans les deux sens : un attaquant ne bloque pas la vérification de
     * toute une entreprise en s'acharnant sur un compte, et l'IP reste bornée
     * pour qu'il ne balaie pas mille adresses depuis la même machine.
     */
    private function registerRateLimiters(): void
    {
        // Envoi d'un code : chaque appel expédie un courriel vers une adresse
        // choisie par l'appelant. Le plafond est bas parce que le coût, ici,
        // est supporté par le destinataire.
        RateLimiter::for('otp-send', fn (Request $request) => [
            Limit::perMinute(3)->by('otp-send:'.$this->emailKey($request)),
            Limit::perMinute(10)->by('otp-send-ip:'.$request->ip()),
        ]);

        // Vérification : chaque appel essaie un secret à six chiffres. Le
        // compteur d'essais par compte ferme la porte pour un compte donné ;
        // celui-ci empêche de la contourner en balayant plusieurs comptes.
        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('otp-verify:'.$this->emailKey($request)),
            Limit::perMinute(30)->by('otp-verify-ip:'.$request->ip()),
        ]);
    }

    /**
     * Clé de comptage tirée de l'adresse visée.
     *
     * Normalisée en minuscules, sans quoi « Jean@example.com » et
     * « jean@example.com » ouvriraient deux quotas pour un même compte.
     */
    private function emailKey(Request $request): string
    {
        return mb_strtolower(trim((string) $request->input('email')));
    }
}
