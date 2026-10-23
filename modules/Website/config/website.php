<?php

declare(strict_types=1);

return [
    // Curated, self-hosted pairings (no Google Fonts requests from public sites).
    'font_pairings' => [
        'modern' => ['label' => 'Modern — Plus Jakarta Sans & Inter', 'display' => 'Plus Jakarta Sans', 'body' => 'Inter'],
        'classic' => ['label' => 'Classic — Playfair Display & Source Sans 3', 'display' => 'Playfair Display', 'body' => 'Source Sans 3'],
        'friendly' => ['label' => 'Friendly — Nunito & Nunito Sans', 'display' => 'Nunito', 'body' => 'Nunito Sans'],
        'elegant' => ['label' => 'Elegant — Cormorant Garamond & Lato', 'display' => 'Cormorant Garamond', 'body' => 'Lato'],
        'bold' => ['label' => 'Bold — Archivo & Archivo', 'display' => 'Archivo', 'body' => 'Archivo'],
        'clean' => ['label' => 'Clean — DM Sans & DM Sans', 'display' => 'DM Sans', 'body' => 'DM Sans'],
        'editorial' => ['label' => 'Editorial — Fraunces & Work Sans', 'display' => 'Fraunces', 'body' => 'Work Sans'],
        'technical' => ['label' => 'Technical — Space Grotesk & IBM Plex Sans', 'display' => 'Space Grotesk', 'body' => 'IBM Plex Sans'],
    ],

    'corner_styles' => ['square', 'soft', 'round'],

    // Slugs that belong to the site engine (sitemaps, assets, feeds — Sprint 5).
    'reserved_slugs' => ['api', 'assets', 'build', 'cart', 'checkout', 'feed', 'llms.txt', 'login', 'robots.txt', 'search', 'sitemap', 'sitemap.xml', 'static', 'wurk'],

    'max_document_bytes' => 512 * 1024,

    // Photo uploads (Sprint 4.3). Phone photos are often 4–8 MB; everything is
    // resized, so the limit is generous while the stored result stays small.
    'uploads' => [
        'max_kb' => 10 * 1024,
        'max_side' => 8000,
        'max_pixels' => 40_000_000,
        'widths' => [640, 1600],
        'webp_quality' => 80,
    ],
    'max_blocks' => 80,
];
