<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Http\UploadedFile;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\MediaLibrary;
use Wurk\Website\Models\SiteAsset;

/** The business's photo library — used by the website editor and, through the contract, by other modules. */
final readonly class SiteMediaLibrary implements MediaLibrary
{
    public function __construct(private SiteAssets $assets, private TenantContext $context, private Entitlements $entitlements) {}

    public function library(): array
    {
        return [
            'photos' => array_values(SiteAsset::query()->latest()->limit(200)->get()
                ->map(fn (SiteAsset $a) => [...$this->assets->photo($a, (string) $a->description), 'id' => $a->id, 'name' => $a->name, 'ai' => $a->ai_generated])->all()),
            'used_mb' => round($this->assets->usedBytes() / 1048576, 1),
            'limit_mb' => (int) ($this->entitlements->limits($this->context->id())['storage_mb'] ?? 0),
        ];
    }

    public function upload(UploadedFile $file, ?string $by, string $alt = ''): array
    {
        return $this->assets->photo($this->assets->store($file, $by), $alt);
    }
}
