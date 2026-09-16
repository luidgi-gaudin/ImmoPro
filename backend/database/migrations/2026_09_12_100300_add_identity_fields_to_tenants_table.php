<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche locataire : identité complète, archivage, et rattachement au compte de
 * connexion du locataire.
 *
 * Attention à la lecture de cette table : `user_id` désigne le **bailleur**
 * propriétaire de la fiche — c'est sur lui que repose toute l'isolation des
 * données. `account_user_id` désigne le **locataire lui-même**, quand il a
 * ouvert un compte. Les deux pointent vers `users` et ne se confondent jamais :
 * la fiche appartient au bailleur, le compte appartient au locataire.
 *
 * Ce second lien est nullable et le restera : la grande majorité des dossiers
 * se gèrent sans que le locataire se connecte jamais. Le rendre obligatoire
 * reviendrait à interdire d'enregistrer un bail tant que le locataire n'a pas
 * cliqué sur un lien d'invitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('last_name');
            $table->string('birth_place')->nullable()->after('birth_date');

            $table->string('identity_document_type', 30)->nullable()->after('birth_place');

            /*
             * Numéro de pièce d'identité, chiffré au repos comme l'IBAN.
             *
             * C'est une donnée d'identification directe au sens du RGPD : elle
             * ouvre l'usurpation d'identité, pas seulement le démarchage. Le
             * type `text` est imposé par le chiffrement, dont la sortie est
             * bien plus longue que le numéro d'origine.
             */
            $table->text('identity_document_number')->nullable()->after('identity_document_type');

            // Portrait, facultatif. Sur le disque privé, jamais en URL publique.
            $table->string('photo_path')->nullable()->after('identity_document_number');

            /*
             * Archivage, distinct de la suppression.
             *
             * Un dossier clos doit sortir des listes de travail sans disparaître :
             * la prescription des actions en paiement des loyers est de trois ans
             * (art. 7-1 de la loi de 1989), et l'ancien locataire peut réclamer
             * son dépôt de garantie bien après son départ. Le `softDeletes`
             * existant répond à « supprimé par erreur », pas à « dossier clos,
             * à conserver ».
             */
            $table->timestamp('archived_at')->nullable()->after('address');

            $table->foreignId('account_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropConstrainedForeignId('account_user_id');

            $table->dropColumn([
                'birth_date',
                'birth_place',
                'identity_document_type',
                'identity_document_number',
                'photo_path',
                'archived_at',
            ]);
        });
    }
};
