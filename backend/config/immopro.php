<?php

/**
 * Réglages propres à ImmoPro, hors conventions Laravel.
 *
 * Tout ce qui touche à l'isolation des données et à la durée de vie des
 * sessions vit ici plutôt qu'éparpillé dans les classes : ce sont des décisions
 * de sécurité, elles doivent être lisibles en un seul endroit.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Row Level Security
    |--------------------------------------------------------------------------
    |
    | L'isolation des données est appliquée par PostgreSQL lui-même, pas
    | seulement par le code. Chaque requête annonce l'identité du bailleur via
    | un paramètre de session, que les policies comparent au propriétaire de la
    | ligne.
    |
    | Deux conditions pour que ce soit autre chose qu'un décor :
    |
    |   - le rôle de connexion ne doit ni posséder les tables ni porter
    |     BYPASSRLS (le rôle `postgres` de Supabase a les deux, d'où le rôle
    |     applicatif dédié créé par `php artisan immopro:rls-provision`) ;
    |   - le paramètre doit être posé avant la première requête métier, ce dont
    |     se charge App\Support\Rls.
    |
    */

    'rls' => [
        // Mettre à false désactive uniquement la pose du paramètre côté PHP.
        // Les policies restent en base : si elles sont actives, tout sera
        // refusé plutôt qu'ouvert. La désactivation ne crée donc pas de faille.
        'enabled' => (bool) env('DB_RLS_ENABLED', true),

        // Nom du paramètre de session PostgreSQL. Doit correspondre à celui que
        // lit la fonction public.app_user_id() créée par la migration.
        'setting' => env('DB_RLS_SETTING', 'app.user_id'),

        // Rôle applicatif soumis aux policies.
        'role' => env('DB_RLS_ROLE', 'immopro_app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Durée de vie des sessions
    |--------------------------------------------------------------------------
    |
    | Deux plafonds distincts, tous deux nécessaires :
    |
    |   - `ttl_minutes` : durée absolue depuis la connexion. Un jeton volé n'est
    |     pas exploitable indéfiniment, même si la victime reste active.
    |   - `idle_minutes` : durée sans activité. Un poste laissé ouvert se ferme
    |     tout seul.
    |
    | `warn_seconds` est le préavis envoyé au front pour proposer « rester
    | connecté » avant la coupure — une déconnexion en pleine saisie fait perdre
    | le travail en cours.
    |
    */

    'session' => [
        'ttl_minutes' => (int) env('SESSION_TOKEN_TTL', 720),
        'idle_minutes' => (int) env('SESSION_IDLE_TIMEOUT', 120),
        'warn_seconds' => (int) env('SESSION_WARN_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Codes à usage unique
    |--------------------------------------------------------------------------
    |
    | Employés pour vérifier une adresse e-mail à l'inscription et confirmer un
    | changement d'adresse. Le code est stocké haché ; ces trois réglages sont
    | ce qui l'empêche d'être deviné ou transformé en robinet à courriels.
    |
    | `max_attempts` est le plus important : six chiffres, c'est un million de
    | combinaisons, et quelques milliers d'essais suffisent à tomber juste assez
    | souvent pour que l'attaque en vaille la peine. Le monter au-delà d'une
    | dizaine revient à désactiver la protection.
    |
    */

    'otp' => [
        'validity_minutes' => (int) env('OTP_VALIDITY_MINUTES', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_interval_seconds' => (int) env('OTP_RESEND_INTERVAL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | Les pièces jointes ne sont jamais servies en URL publique : un bail signé
    | ou une pièce d'identité qui fuite par une URL devinable serait une
    | violation de données. Elles vivent sur un disque privé et ne sortent que
    | par une URL signée, à durée limitée.
    |
    */

    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 'documents'),
        'max_size_kb' => (int) env('DOCUMENTS_MAX_SIZE_KB', 20480),
        'link_ttl_minutes' => (int) env('DOCUMENTS_LINK_TTL', 5),
        'mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],
];
