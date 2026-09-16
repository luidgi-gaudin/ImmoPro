<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mot de passe facultatif, pour les comptes ouverts par Google ou Apple.
 *
 * Un compte créé par un fournisseur externe n'a pas de mot de passe, et lui en
 * inventer un au hasard pour satisfaire la colonne serait pire que de laisser
 * la case vide : la base porterait alors un condensat que personne ne peut
 * produire, sans rien qui le signale. Impossible dès lors de répondre à la
 * seule question qui compte au moment de détacher un fournisseur — reste-t-il à
 * cette personne un autre moyen d'entrer ? Faute de le savoir, on détache, et
 * on enferme quelqu'un dehors.
 *
 * `null` répond à la question. Voir User::hasUsablePassword().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
