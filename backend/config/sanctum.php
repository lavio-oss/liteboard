<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [
    'stateful' => array_values(array_filter(array_map(
        static fn (string $domain): string => trim($domain),
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
            '%s,%s',
            'localhost:3000',
            Sanctum::currentApplicationUrlWithPort(),
        ))),
    ))),

    'guard' => ['web'],

    // Per-token expires_at is authoritative; this global override stays disabled.
    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'default_token_expiration_days' => (int) env('SANCTUM_DEFAULT_TOKEN_EXPIRATION_DAYS', 30),

    'max_token_expiration_days' => (int) env('SANCTUM_MAX_TOKEN_EXPIRATION_DAYS', 90),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
