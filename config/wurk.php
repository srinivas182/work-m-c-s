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
            'forgot-password', 'mfa', 'health', 'build', 'storage', 'invitations', '_blocks', 'templates', 'forms', 'settings', 'webhooks', 'signup',
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
            // Production default 20; browser tests send many codes from one IP and raise it (scripts/e2e-setup).
            'per_ip_per_hour' => (int) env('WURK_OTP_PER_IP_PER_HOUR', 20),
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

    /*
    | Module catalogue for packages. Keys match each module's manifest
    | "entitlement" key; modules not built yet can already be sold, and
    | switch on automatically when they ship with the same key.
    */
    'modules' => [
        'website' => ['name' => 'Website & page builder', 'sprint' => 4],
        'bookings' => ['name' => 'Appointments', 'sprint' => 7],
        'shop' => ['name' => 'Online shop', 'sprint' => 8],
        'multi-branch' => ['name' => 'Multiple branches', 'sprint' => 9],
        'crm' => ['name' => 'CRM & leads', 'sprint' => 10],
        'chatbot' => ['name' => 'Website chatbot', 'sprint' => 10],
        'referrals' => ['name' => 'Customer referral programme', 'sprint' => 11],
        'marketing' => ['name' => 'Campaigns & offers', 'sprint' => 13],
        'reports' => ['name' => 'Reports', 'sprint' => 13],
        'ai-assistant' => ['name' => 'WURK AI', 'sprint' => 6],
    ],

    'security' => [
        // Emergency switch: WURK_ADMIN_IP_ALLOWLIST=false ignores the admin IP allow-list.
        'admin_ip_allowlist' => (bool) env('WURK_ADMIN_IP_ALLOWLIST', true),
    ],

    // Where website enquiries from wurk.co.za/contact are sent.
    'contact_email' => env('WURK_CONTACT_EMAIL'),

    // Server-side render wurk.co.za (needs the "ssr" process; falls back to browser rendering).
    'marketing_ssr' => (bool) env('WURK_MARKETING_SSR', true),

    // Where website photos are stored (S3-compatible in production; Sprint 4.3).
    'site_assets_disk' => env('WURK_SITE_ASSETS_DISK', 'public'),
];
