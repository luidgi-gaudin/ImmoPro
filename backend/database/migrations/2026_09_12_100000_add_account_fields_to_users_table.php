<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compte utilisateur : profil choisi à l'inscription, coordonnées modifiables,
 * préférences de notification et code à usage unique.
 *
 * La colonne `role` existait déjà mais n'était lue nulle part : elle portait
 * « proprietaire » pour tout le monde, y compris pour un locataire. Elle
 * devient ici la donnée qui décide de l'écran d'arrivée et des routes ouvertes,
 * d'où l'index — elle est désormais filtrée, plus seulement affichée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');

            // Photo de profil, sur le disque privé des documents. Jamais
            // d'URL publique : c'est une donnée personnelle comme une autre.
            $table->string('avatar_path')->nullable()->after('phone');

            /*
             * Écarts aux réglages de notification par défaut (voir
             * App\Support\NotificationPreferences). Ne stocke que ce que
             * l'utilisateur a explicitement changé : un sujet ajouté plus tard
             * arrive ainsi allumé, au lieu de naître éteint pour les comptes
             * déjà créés.
             */
            $table->json('notification_preferences')->nullable()->after('currency');

            /*
             * Vérification par code à usage unique.
             *
             * Le code est stocké haché, jamais en clair : la table des
             * utilisateurs n'est pas couverte par la RLS (elle est lue avant
             * qu'une identité existe), et un code en clair y serait un mot de
             * passe temporaire lisible.
             *
             * `otp_attempts` n'est pas décoratif : six chiffres se devinent en
             * un million d'essais, quelques milliers suffisent à tomber juste
             * assez souvent. Le compteur ferme la porte avant.
             */
            /*
             * Adresse en attente de confirmation, lors d'un changement.
             *
             * La nouvelle adresse ne remplace l'ancienne qu'une fois le code
             * saisi. Écrire tout de suite et redemander une vérification
             * paraîtrait plus simple, mais une faute de frappe enfermerait
             * alors le compte dehors : la connexion exige une adresse vérifiée,
             * et le code partirait vers une boîte qui n'existe pas.
             */
            $table->string('pending_email')->nullable()->after('email');

            $table->string('otp_code_hash')->nullable()->after('two_factor_confirmed_at');
            $table->string('otp_purpose', 40)->nullable()->after('otp_code_hash');
            $table->timestamp('otp_sent_at')->nullable()->after('otp_purpose');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_sent_at');
            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at');

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);

            $table->dropColumn([
                'phone',
                'pending_email',
                'avatar_path',
                'notification_preferences',
                'otp_code_hash',
                'otp_purpose',
                'otp_sent_at',
                'otp_expires_at',
                'otp_attempts',
            ]);
        });
    }
};
