<?php

declare(strict_types=1);

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
            'status', 'support', 'www', 'wurk',
        ],

        // Default capacity of a shared shard (tenants per database).
        'shard_capacity' => (int) env('WURK_SHARD_CAPACITY', 5000),

        // Seconds tenant lookups by handle/host are cached.
        'resolution_cache_ttl' => 600,
    ],

];
