<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiActionRefused;
use Wurk\Ai\Contracts\AiActions;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Support\SiteAssets;

/** "Generate an image" from the photo picker: confirmed with its price first; decorative use only. */
final class AiImageController
{
    public function __invoke(Request $request, Ai $ai, AiActions $actions, SiteAssets $assets, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:300'],
            'confirmation' => ['required', 'string', 'max:64'],
        ]);
        try {
            $asset = $actions->perform('image', (string) $data['confirmation'], function () use ($ai, $assets, $context, $data, $request) {
                $bytes = $ai->image((string) $data['prompt'], $context->id());

                return $assets->storeGenerated($bytes, 'AI image', mb_substr((string) $data['prompt'], 0, 200), (string) $request->user()?->getAuthIdentifier());
            });
        } catch (AiActionRefused|AiUnavailable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse([...$assets->photo($asset, (string) $asset->description), 'id' => $asset->id, 'ai' => true], 201);
    }
}
