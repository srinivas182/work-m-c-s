<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

/** One image provider. Returns the image file's bytes; throws ProviderFailed otherwise. */
interface ImageDriver
{
    public function generate(string $model, string $prompt, string $apiKey): string;
}
