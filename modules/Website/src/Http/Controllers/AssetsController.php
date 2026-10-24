<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\SiteAsset;
use Wurk\Website\Support\SiteAssets;

/** Website photos for the editor's photo picker. */
final class AssetsController
{
    public function __construct(private readonly SiteAssets $assets) {}

    public function index(TenantContext $context, Entitlements $entitlements): JsonResponse
    {
        return new JsonResponse([
            'photos' => SiteAsset::query()->latest()->limit(200)->get()->map(fn (SiteAsset $a) => [...$this->assets->photo($a, (string) $a->description), 'id' => $a->id, 'name' => $a->name, 'ai' => $a->ai_generated])->all(),
            'used_mb' => round($this->assets->usedBytes() / 1048576, 1),
            'limit_mb' => (int) ($entitlements->limits($context->id())['storage_mb'] ?? 0),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file']], ['photo.required' => 'Choose a photo to upload.']);
        $asset = $this->assets->store($request->file('photo'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse($this->assets->photo($asset, (string) $request->input('alt', '')), 201);
    }
}
