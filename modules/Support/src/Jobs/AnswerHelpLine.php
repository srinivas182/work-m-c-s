<?php

declare(strict_types=1);

namespace Wurk\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wurk\Support\Support\HelpLine;

/** One message to the WhatsApp help line, answered off the webhook (Ask WURK takes a few seconds). */
final class AnswerHelpLine implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1; // never answer the same message twice

    public int $timeout = 120;

    public function __construct(public readonly string $from, public readonly string $text, public readonly ?string $mediaId, public readonly ?string $mediaMime,
        public readonly ?string $buttonId = null) {}

    public function handle(HelpLine $helpLine): void
    {
        $helpLine->handle($this->from, $this->text, $this->mediaId, $this->mediaMime, $this->buttonId);
    }
}
