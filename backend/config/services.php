<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fournisseurs d'identité (Sign in with Google / Apple)
    |--------------------------------------------------------------------------
    |
    | Seul l'identifiant client est nécessaire : l'application ne joue pas le
    | rôle de client OAuth, elle se contente de vérifier le jeton d'identité que
    | le navigateur a déjà obtenu. Aucun secret ne transite donc par ce serveur,
    | et il n'y a rien à protéger de plus que ce fichier.
    |
    | Cet identifiant n'est pas une donnée décorative : c'est lui que la
    | revendication `aud` du jeton doit porter. Sans lui, un jeton signé par
    | Google mais émis pour une autre application serait accepté. Un
    | fournisseur laissé vide est simplement désactivé.
    |
    | Plusieurs valeurs peuvent être déclarées, séparées par une virgule : Apple
    | émet sous l'identifiant de l'application iOS et sous celui du service web,
    | qui diffèrent.
    |
    */

    'google' => [
        'client_id' => array_filter(explode(',', (string) env('GOOGLE_CLIENT_ID', ''))),
    ],

    'apple' => [
        'client_id' => array_filter(explode(',', (string) env('APPLE_CLIENT_ID', ''))),
    ],

];
