<?php

declare(strict_types=1);

namespace Wurk\Ai\Console;

use Illuminate\Console\Command;
use Wurk\Ai\Models\SalesConversation;

/** Sales assistant conversations are kept for 30 days (visitors are told), then deleted (Sprint 19.5). */
final class SweepSalesConversationsCommand extends Command
{
    protected $signature = 'ai:sweep-sales-conversations';

    protected $description = 'Delete WURK sales assistant conversations older than 30 days';

    public function handle(): int
    {
        $deleted = SalesConversation::query()->where('last_message_at', '<', now()->subDays(SalesConversation::KEEP_DAYS))->delete();
        $this->line("Conversations deleted: {$deleted}.");

        return self::SUCCESS;
    }
}
