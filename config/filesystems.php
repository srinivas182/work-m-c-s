<?php

declare(strict_types=1);

/*
 * Sprint 17.1: in the cloud (FILES_DRIVER=s3) every disk is a prefix of the environment's private, versioned bucket
 * (FILES_BUCKET), with credentials from the containers' role — no keys in the environment. Locally and in tests the
 * disks stay folders under storage/app. Public website files are served through CloudFront (FILES_PUBLIC_URL).
 *
 * @param  array<string, mixed>  $local  the disk on this machine
 * @return array<string, mixed>
 */
$disk = static function (string $prefix, array $local): array {
    if (env('FILES_DRIVER', 'local') !== 's3') {
        return $local;
    }

    return [
        'driver' => 's3',
        'region' => env('AWS_DEFAULT_REGION', 'af-south-1'),
        'bucket' => env('FILES_BUCKET'),
        'root' => $prefix,
        'url' => $prefix === 'public' ? rtrim((string) env('FILES_PUBLIC_URL', ''), '/') : null,
        'visibility' => 'private', // the bucket never serves files publicly; CloudFront reads public/ only
        'throw' => $local['throw'] ?? false,
        'report' => $local['report'] ?? false,
    ];
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        // Patient records (Sprint 9.5): files are encrypted before they're written; never public.
        // Files visitors attach to forms: encrypted, private, never on the website's address.
        'form-files' => $disk('form-files', [
            'driver' => 'local',
            'root' => storage_path('app/form-files'),
            'visibility' => 'private',
            'throw' => true,
        ]),

        // Verified business (Sprint 25.4): ID documents and selfies, encrypted before they're written; deleted 90 days
        // after HQ's decision. Never public.
        'trust' => $disk('trust', [
            'driver' => 'local',
            'root' => storage_path('app/trust'),
            'visibility' => 'private',
            'throw' => true,
        ]),

        'records' => $disk('records', [
            'driver' => 'local',
            'root' => storage_path('app/records'),
            'visibility' => 'private',
            'throw' => true,
        ]),

        'local' => $disk('private', [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false, // no public /storage/{path} routes: private files are sent by their own controllers (18.1)
            'throw' => false,
            'report' => false,
        ]),

        'public' => $disk('public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ]),

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
