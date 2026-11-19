<?php

declare(strict_types=1);

namespace Wurk\Ai\Languages;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Drafts a language's missing video subtitles in the background (Admin → Languages → Video subtitles, 25.6). */
final class DraftCaptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public function __construct(public string $locale) {}

    public function handle(CaptionDrafts $drafts): void
    {
        $drafts->draft($this->locale);
    }
}
