<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Website\Contracts\MediaLibrary;

/** Website photos for the editor's photo picker. */
final class AssetsController
{
    public function __construct(private readonly MediaLibrary $media) {}

    public function index(): JsonResponse
    {
        return new JsonResponse($this->media->library());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file']], ['photo.required' => 'Choose a photo to upload.']);

        return new JsonResponse($this->media->upload($request->file('photo'), (string) $request->user()?->getAuthIdentifier(), (string) $request->input('alt', '')), 201);
    }
}
