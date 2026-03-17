<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * The allowed SPA/frontend origin is driven by the SPA_ORIGIN environment variable.
     *
     * Make sure to define SPA_ORIGIN in your .env (and document it in .env.example / README)
     * for each environment (e.g. https://app.example.com). If SPA_ORIGIN is not set,
     * it will fall back to http://localhost:4200 for local development.
     */
    'allowed_origins' => [env('SPA_ORIGIN', 'http://localhost:4200')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
