<?php

declare(strict_types=1);

namespace Wurk\Ai\Languages;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Drafts a language's missing texts in the background (Admin → Languages → Draft with WURK AI). */
final class DraftTranslationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public function __construct(public string $locale) {}

    public function handle(TranslationDrafts $drafts): void
    {
        $drafts->draft($this->locale, 5000);
    }
}
