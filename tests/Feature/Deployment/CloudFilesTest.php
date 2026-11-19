<?php

declare(strict_types=1);

/*
 * Sprint 17.1: in the cloud every disk is its own prefix of the environment's private bucket — never a public bucket
 * and never keys in the environment — and website files get CloudFront's address. Locally nothing changes.
 */

function filesystemsWith(array $env): array
{
    $before = [];
    foreach ($env as $k => $v) {
        $before[$k] = getenv($k);
        putenv("{$k}={$v}");
        $_ENV[$k] = $_SERVER[$k] = $v;
    }
    try {
        return require base_path('config/filesystems.php');
    } finally {
        foreach ($before as $k => $v) {
            $v === false ? putenv($k) : putenv("{$k}={$v}");
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }
}

it('puts every disk in its own private prefix of the bucket in the cloud', function () {
    $disks = filesystemsWith(['FILES_DRIVER' => 's3', 'FILES_BUCKET' => 'wurk-production-files-123', 'FILES_PUBLIC_URL' => 'https://files.wurk.co.za/'])['disks'];

    foreach (['form-files' => 'form-files', 'trust' => 'trust', 'records' => 'records', 'local' => 'private', 'public' => 'public'] as $disk => $prefix) {
        expect($disks[$disk])->toMatchArray(['driver' => 's3', 'bucket' => 'wurk-production-files-123', 'root' => $prefix, 'visibility' => 'private'])
            ->and($disks[$disk])->not->toHaveKeys(['key', 'secret']); // the containers' role, not keys
    }
    expect($disks['public']['url'])->toBe('https://files.wurk.co.za')
        ->and($disks['trust']['url'])->toBeNull()
        ->and($disks['trust']['throw'])->toBeTrue(); // ID documents: a failed write is an error, as locally
});

it('keeps the folders under storage/app when not in the cloud', function () {
    $disks = filesystemsWith(['FILES_DRIVER' => 'local'])['disks'];

    expect($disks['trust'])->toMatchArray(['driver' => 'local', 'root' => storage_path('app/trust')])
        ->and($disks['public']['driver'])->toBe('local');
});
