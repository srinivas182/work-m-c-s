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

    // Malware scanning of files visitors upload (ClamAV's clamd, in its own container).
    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

    // ffmpeg and ffprobe re-encode website videos and take their poster frame (Sprint 20.2; installed in the image).
    'ffmpeg' => [
        'binary' => env('WURK_FFMPEG', 'ffmpeg'),
        'probe' => env('WURK_FFPROBE', 'ffprobe'),
    ],

    // Customers' WhatsApp voice notes (Sprint 21.4): the audio is deleted after this many days; the transcript stays.
    'whatsapp' => [
        'voice_note_days' => (int) env('WURK_VOICE_NOTE_DAYS', 30),
    ],

    'domains' => [
        'root' => $root,
        'admin' => env('WURK_ADMIN_DOMAIN', 'admin.'.$root),
        'app' => env('WURK_APP_DOMAIN', 'app.'.$root),
        'my' => env('WURK_MY_DOMAIN', 'my.'.$root), // My WURK: customers' businesses in one place (Sprint 13.8)
        'help' => env('WURK_HELP_DOMAIN', 'help.'.$root), // the public help centre (Sprint 16.1)
    ],

    // Owners' own domains (Sprint 17.3) through Cloudflare for SaaS. The token and zone are infrastructure secrets
    // (Secrets Manager in the cloud); without them, Settings → Your domain says the service isn't set up yet.
    'custom_domains' => [
        'cloudflare_token' => env('CLOUDFLARE_API_TOKEN'),
        'cloudflare_zone' => env('CLOUDFLARE_ZONE_ID'),
        // Owners point their domain here (a CNAME); Cloudflare sends their visitors to WURK's origin.
        'target' => env('WURK_CUSTOM_DOMAIN_TARGET', 'sites.'.$root),
        'origin' => env('WURK_CUSTOM_DOMAIN_ORIGIN', 'origin.'.$root),
        'per_business' => 2, // e.g. www.glowsalon.co.za and glowsalon.com
        'pending_days' => 7, // a domain never pointed at WURK is let go after this
    ],

    'tenancy' => [
        // Business handles that can never be claimed (they collide with platform hosts or routes).
        'reserved_handles' => [
            'admin', 'api', 'app', 'assets', 'auth', 'billing', 'blog', 'cdn', 'dashboard', 'docs',
            '.well-known', 'help', 'login', 'logout', 'mail', 'my', 'agent', 'partners', 'register', 'signup', 'static',
            'status', 'support', 'i18n', 'www', 'wurk', 'sign-in', 'sign-out', 'account', 'businesses',
            'forgot-password', 'mfa', 'health', 'build', 'storage', 'invitations', '_blocks', 'templates', 'forms', 'settings', 'webhooks', 'whatsapp', 'signup', 'oauth',
            'pay',
            'payments',
            'origin', 'sites', 'files', 'staging', // the cloud's own addresses (Sprint 17)
        ],

        // Default capacity of a shared shard (tenants per database).
        'shard_capacity' => (int) env('WURK_SHARD_CAPACITY', 5000),

        // The shard migrated (and health-checked) first on every rollout — Sprint 18.4b. Empty: the first by name.
        'canary_shard' => (string) env('WURK_CANARY_SHARD', ''),

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
            'portal' => ['idle' => 120, 'absolute' => 43200, 'remember' => true], // customers on a business's own site
            'my' => ['idle' => 120, 'absolute' => 43200, 'remember' => true], // My WURK
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

    // Where website enquiries from wurk.co.za/contact are sent.
    'contact_email' => env('WURK_CONTACT_EMAIL'),

    // Server-side render wurk.co.za (needs the "ssr" process; falls back to browser rendering).
    'marketing_ssr' => (bool) env('WURK_MARKETING_SSR', true),

    // Where website photos are stored (S3-compatible in production; Sprint 4.3).
    // Published pages are rendered once, at publish, by Node (bootstrap/site-render).
    'site_render' => [
        'node' => env('WURK_NODE_BINARY', 'node'),
        'script' => base_path('bootstrap/site-render/site-render.js'),
        'timeout' => 30,
    ],
    'site_pages_disk' => env('WURK_SITE_PAGES_DISK', 'local'),

    'site_assets_disk' => env('WURK_SITE_ASSETS_DISK', 'public'),
    // Install WURK's chosen demo photos (modules/Website/templates/photos/photos.json) with the templates, so a new
    // installation's designs show real photos at once. Off where the server can't reach Pexels (tests, offline e2e).
    'template_demo_photos' => (bool) env('WURK_TEMPLATE_DEMO_PHOTOS', true),
    // Agents' demo sites (Sprint 14.8): how long a demo stays up, and how long its claim link works.
    'demo_days' => 14,
    // Support tickets (Sprint 16.3): WURK's support inbox email — new tickets and replies are sent here.
    'support_email' => env('WURK_SUPPORT_EMAIL', 'support@wurk.co.za'),

    // Security headers (Sprint 18.1): the Content Security Policy — enforce (default) | report-only | off; HSTS over HTTPS.
    'security' => [
        'csp' => env('WURK_CSP', 'enforce'),
        'hsts' => (bool) env('WURK_HSTS', true),
        // Who to tell about a security problem (security.txt and the disclosure page).
        'contact' => env('WURK_SECURITY_EMAIL', 'security@wurk.co.za'),
        // The load balancer's addresses (comma-separated), or "*" when the servers are reachable only through it:
        // WURK then sees each visitor's IP (rate limits, audit) and HTTPS (HSTS, secure cookies). Unset: trust nobody.
        'trusted_proxies' => env('TRUSTED_PROXIES', ''),
        // Emergency switch: WURK_ADMIN_IP_ALLOWLIST=false ignores the admin IP allow-list.
        'admin_ip_allowlist' => (bool) env('WURK_ADMIN_IP_ALLOWLIST', true),
    ],

    // Load tests on staging (Sprint 18.3b): the secret that lets test machines play many visitors. Never in production.
    'load_test' => ['token' => env('WURK_LOAD_TEST_TOKEN', '')],

    // The running release (Sprint 18.0): set at build time; otherwise the VERSION file at the root.
    'release' => env('WURK_RELEASE') ?: (is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev'),
    'commit' => env('WURK_COMMIT'),

    // Watchtower (Sprint 18.0): who gets problem reports until HQ sets its own list in Admin → Watchtower.
    'watchtower' => [
        'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('WATCHTOWER_EMAIL', ''))))),
        // Secret in the external uptime checker's webhook address: /_wurk/uptime/{token} (18.0b). Unset = no webhook.
        'uptime_token' => env('WATCHTOWER_UPTIME_TOKEN'),
        // The cloud's alarms through Amazon SNS (17.5): POST /_wurk/alarms/{token}, from this topic only.
        'alarm_token' => env('WATCHTOWER_ALARM_TOKEN'),
        'alarm_topic' => env('WATCHTOWER_ALARM_TOPIC'),
        // The AI explains problems (18.0b): on/off, and how many a day at most (WURK's cost, not businesses').
        'ai' => (bool) env('WATCHTOWER_AI', true),
        'ai_daily_cap' => (int) env('WATCHTOWER_AI_DAILY_CAP', 50),
        // Backups (18.4c): the targets that must have a recent backup (e.g. "central,shard-1,files"), how old the latest
        // may be before it's a problem, and WURK's recovery targets (PRD §15): data lost ≤ 15 min, back within 4 hours.
        'backup_targets' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_TARGETS', ''))))),
        'backup_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
        'rpo_minutes' => 15,
        'rto_minutes' => 240,
        // Share of web requests timed for the slow-pages check (every request over 2 s is always counted).
        'timing_sample' => (float) env('WATCHTOWER_TIMING_SAMPLE', 0.02),
        // Extra queues to watch for backlogs, besides the connection's default.
        'queues' => array_values(array_filter(explode(',', (string) env('WATCHTOWER_QUEUES', '')))),
    ],
];
