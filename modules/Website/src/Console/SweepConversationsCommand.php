<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\ReceptionistConversation;

/**
 * Daily (Sprint 19.2): AI receptionist conversations that led nowhere are deleted 30 days after their last message,
 * as the customer was told. One that led to a booking, an order or a hand-off is kept with the customer's records.
 */
final class SweepConversationsCommand extends Command
{
    protected $signature = 'website:sweep-conversations';

    protected $description = 'Delete AI receptionist conversations that led nowhere after 30 days';

    public function handle(TenantDirectory $directory, TenantContext $context): int
    {
        $deleted = 0;
        foreach ($directory->activeIds() as $id) {
            $tenant = $directory->find($id);
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use (&$deleted): void {
                $deleted += ReceptionistConversation::query()->where('keep', false)
                    ->where('last_message_at', '<', now()->subDays(ReceptionistConversation::KEEP_DAYS))->delete(); // messages go with them
            });
        }
        $this->info("Conversations deleted: {$deleted}.");

        return self::SUCCESS;
    }
}
