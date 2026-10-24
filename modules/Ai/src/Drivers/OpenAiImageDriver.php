<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** OpenAI Images API (POST /v1/images/generations), landscape for hero and section images. */
final class OpenAiImageDriver implements ImageDriver
{
    public function generate(string $model, string $prompt, string $apiKey): string
    {
        try {
            $r = Http::timeout(120)->acceptJson()->withToken($apiKey)
                ->post('https://api.openai.com/v1/images/generations', ['model' => $model, 'prompt' => $prompt, 'size' => '1536x1024', 'n' => 1]);
        } catch (ConnectionException) {
            throw new ProviderFailed('OpenAI unreachable');
        }
        $b64 = $r->json('data.0.b64_json');
        if (! $r->successful() || ! is_string($b64) || ($bytes = base64_decode($b64, true)) === false) {
            throw new ProviderFailed('OpenAI: '.($r->json('error.message') ?? 'no image returned'));
        }

        return $bytes;
    }
}
