<?php

declare(strict_types=1);

namespace Wurk\Messaging\Console;

use Illuminate\Console\Command;
use Wurk\Messaging\Support\OwnerSummaries;

/** Every 15 minutes (Sprint 21.6): owners' evening WhatsApp summaries that are due, at each owner's chosen time. */
final class SendOwnerSummariesCommand extends Command
{
    protected $signature = 'messaging:owner-summaries';

    protected $description = 'Send owners’ evening WhatsApp summaries that are due';

    public function handle(OwnerSummaries $summaries): int
    {
        $this->info('Summaries sent: '.$summaries->sendDue().'.');

        return self::SUCCESS;
    }
}
