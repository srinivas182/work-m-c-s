<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Website\Contracts\MediaLibrary;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\TemplatePhotos;
use Wurk\Website\Support\Templates;

/** Website photos for the editor's photo picker. */
final class AssetsController
{
    public function __construct(private readonly MediaLibrary $media) {}

    public function index(Templates $templates, TemplatePhotos $photos): JsonResponse
    {
        // The design's demo photos (20.3), so an owner can swap one demo for another that fits better.
        $key = (string) Site::query()->value('template_key');
        $published = $key === '' ? null : $templates->published($key);
        $demo = $published === null ? [] : $photos->demoSet($published['template']);

        return new JsonResponse([...$this->media->library(), 'demo' => $demo]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file']], ['photo.required' => 'Choose a photo to upload.']);

        return new JsonResponse($this->media->upload($request->file('photo'), (string) $request->user()?->getAuthIdentifier(), (string) $request->input('alt', '')), 201);
    }
}
