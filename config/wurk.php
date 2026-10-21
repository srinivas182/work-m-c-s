<?php

declare(strict_types=1);
use Wurk\Identity\Otp\LogOtpChannel;
use Wurk\Identity\Otp\MailOtpChannel;

$root = env('WURK_ROOT_DOMAIN', 'wurk.test');

return [

    /*
    |--------------------------------------------------------------------------
    | Design system showcase
    |--------------------------------------------------------------------------
    | The /_design page shows every token and component for visual sign-off.
    | Enabled everywhere except production unless explicitly overridden.
    */

    'design_showcase' => (bool) env('WURK_DESIGN_SHOWCASE', env('APP_ENV', 'production') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Hosts
    |--------------------------------------------------------------------------
    | admin.{root}  WURK staff back office
    | app.{root}    Business back office: app.{root}/{business}/…
    | {handle}.{root} or a custom domain: public business websites
    */

    'domains' => [
        'root' => $root,
        'admin' => env('WURK_ADMIN_DOMAIN', 'admin.'.$root),
        'app' => env('WURK_APP_DOMAIN', 'app.'.$root),
    ],

    'tenancy' => [
        // Business handles that can never be claimed (they collide with platform hosts or routes).
        'reserved_handles' => [
            'admin', 'api', 'app', 'assets', 'auth', 'billing', 'blog', 'cdn', 'dashboard', 'docs',
            'help', 'login', 'logout', 'mail', 'my', 'partners', 'register', 'signup', 'static',
            'status', 'support', 'www', 'wurk', 'sign-in', 'sign-out', 'account', 'businesses',
            'forgot-password', 'mfa', 'health', 'build', 'storage',
        ],

        // Default capacity of a shared shard (tenants per database).
        'shard_capacity' => (int) env('WURK_SHARD_CAPACITY', 5000),

        // Seconds tenant lookups by handle/host are cached.
        'resolution_cache_ttl' => 600,
    ],

    'identity' => [
        'otp' => [
            'length' => 6,
            'ttl_seconds' => 300,
            'max_attempts' => 5,
            'resend_after_seconds' => 30,
            'per_destination_per_hour' => 5,
            'per_ip_per_hour' => 20,
            // Channel per destination type. SMS/WhatsApp drivers replace 'phone' in Sprint 2.
            'channels' => [
                'email' => MailOtpChannel::class,
                'phone' => LogOtpChannel::class,
            ],
        ],
        'password' => [
            'min_length' => 10,
            'max_attempts' => 5,
            'lockout_seconds' => 900,
        ],
        // Minutes. "remember" allows a 30-day remember-me cookie on that surface.
        'sessions' => [
            'admin' => ['idle' => 30, 'absolute' => 480, 'remember' => false],
            'app' => ['idle' => 120, 'absolute' => 43200, 'remember' => true],
        ],
        'default_country_code' => '27',
    ],

    'audit' => [
        // Keys whose values are never written to the audit trail (any depth, case-insensitive).
        'redact' => [
            'password', 'password_confirmation', 'current_password', 'secret', 'token', 'remember_token',
            'recovery_codes', 'code', 'code_hash', 'connection', 'api_key', 'card_number', 'cvv',
        ],
    ],

];
