<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pièces jointes de la gestion locative : bail signé, état des lieux, DPE,
 * attestation d'assurance, quittance, pièce d'identité, diagnostics.
 *
 * Une seule table polymorphe plutôt qu'une par entité : les mêmes règles
 * (taille, types acceptés, isolation, URL signée, expiration) valent pour
 * toutes, et un écran unique sait alors les afficher partout.
 *
 * `user_id` est redondant avec le propriétaire déductible de la relation, et
 * c'est voulu : la policy RLS doit pouvoir trancher sans traverser trois
 * jointures, et un document orphelin (entité supprimée) reste rattaché à son
 * bailleur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Bail, bien, locataire ou portefeuille.
            $table->morphs('documentable');

            // Catégorie métier (App\Enums\DocumentCategory) : c'est elle qui
            // porte le sens réglementaire, pas le nom du fichier.
            $table->string('category', 40);

            // Libellé choisi par l'utilisateur ; à défaut, le nom du fichier.
            $table->string('name');
            $table->string('original_name');

            // Chemin sur le disque privé. Jamais exposé au client : les
            // téléchargements passent par une URL signée à durée limitée.
            $table->string('path');
            $table->string('disk', 40)->default('documents');

            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');

            // Date portée par le document lui-même (signature, réalisation du
            // diagnostic), distincte de la date de dépôt.
            $table->date('issued_on')->nullable();

            // Fin de validité, pour les pièces qui en ont une : DPE (10 ans),
            // attestation d'assurance (1 an). Alimente les rappels.
            $table->date('expires_on')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Suppression réversible : effacer un bail signé par erreur ne doit
            // pas être définitif.
            $table->softDeletes();

            $table->index(['user_id', 'category']);
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
