<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes externes (Google, Apple) rattachés à un utilisateur.
 *
 * Une table séparée plutôt que deux colonnes sur `users` : la même personne
 * peut fort bien s'inscrire avec Google puis se connecter avec Apple, ou lier
 * les deux à un compte ouvert par mot de passe. Des colonnes `provider` /
 * `provider_id` uniques sur `users` interdiraient le second rattachement.
 *
 * `provider_user_id` est l'identifiant stable rendu par le fournisseur — le
 * `sub` du jeton d'identité. L'adresse e-mail ne peut pas jouer ce rôle : Apple
 * permet de la relayer derrière un alias jetable, et un utilisateur qui change
 * la sienne chez Google resterait le même compte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 20);
            $table->string('provider_user_id');

            // Adresse renvoyée par le fournisseur au moment du rattachement.
            // Conservée pour information : elle peut différer de celle du
            // compte, et diverger ensuite.
            $table->string('provider_email')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('avatar_url')->nullable();

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            // Un compte externe ne se rattache qu'à un seul utilisateur.
            $table->unique(['provider', 'provider_user_id']);

            // Un utilisateur ne rattache qu'un compte par fournisseur.
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
