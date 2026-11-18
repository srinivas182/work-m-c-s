<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Website\Models\SiteAsset;
use Wurk\Website\Support\SiteAssets;

/**
 * Website videos for the editor's video picker (Sprint 20.2): the video hero and the Video section.
 * Uploads are re-encoded on the server (H.264, ≤ 1280 wide, ≤ 30 seconds) and get a still poster.
 */
final class VideosController
{
    public function __construct(private readonly SiteAssets $assets) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'videos' => SiteAsset::query()->where('kind', 'video')->latest()->limit(100)->get()
                ->map(fn (SiteAsset $a) => [...$this->assets->video($a), 'id' => $a->id, 'name' => $a->name])->values()->all(),
            'max_mb' => (int) config('website.videos.max_mb'),
            'max_seconds' => (int) config('website.videos.max_seconds'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $maxKb = (int) config('website.videos.max_mb') * 1024;
        $request->validate(
            ['video' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:video/mp4,video/quicktime,video/webm,video/3gpp,video/x-matroska']],
            ['video.required' => 'Choose a video to upload.', 'video.max' => 'That video is too large. Use a clip under '.(int) config('website.videos.max_mb').' MB.',
                'video.mimetypes' => 'Upload an MP4, MOV or WebM video.'],
        );
        $asset = $this->assets->storeVideo($request->file('video'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse([...$this->assets->video($asset), 'id' => $asset->id, 'name' => $asset->name], 201);
    }
}
