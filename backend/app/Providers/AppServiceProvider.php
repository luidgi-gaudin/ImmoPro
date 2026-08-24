<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
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
    }
}
