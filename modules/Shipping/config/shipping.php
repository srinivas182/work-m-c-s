<?php

declare(strict_types=1);

// Provider addresses — CONFIRM against each provider's sandbox before go-live (as with Ozow and Peach).
// Plain values (config caching): change them here, not with env().
return [
    'bobgo' => [
        'test' => 'https://api.sandbox.bobgo.co.za/v2',
        'live' => 'https://api.bobgo.co.za/v2',
    ],
    'pargo' => [
        'test' => 'https://api.staging.pargo.co.za',
        'live' => 'https://api.pargo.co.za',
    ],
    'timeout' => 15,
    // Products don't have weights yet: each item counts as this many kilograms for courier rates.
    'item_kg' => 0.5,
];
