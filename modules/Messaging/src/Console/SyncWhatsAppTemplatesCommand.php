<?php

declare(strict_types=1);

namespace Wurk\Messaging\Console;

use Illuminate\Console\Command;
use Throwable;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Messaging\Support\WhatsAppGraph;

/**
 * Hourly (Sprint 21.5): submits WURK's templates that a connected account doesn't have yet (new ones arrive with
 * updates) and refreshes Meta's review of each. Meta's template webhook usually tells WURK first; this catches the rest.
 */
final class SyncWhatsAppTemplatesCommand extends Command
{
    protected $signature = 'messaging:sync-whatsapp-templates';

    protected $description = 'Submit and check WURK’s WhatsApp templates on every connected business account';

    public function handle(WhatsAppGraph $graph): int
    {
        $checked = 0;
        $failed = 0;
        foreach (WhatsAppConnection::query()->where('status', 'connected')->cursor() as $c) {
            try {
                $c->update(['templates' => $graph->ensureTemplates($c->waba_id, $c->token)]);
                $checked++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }
        $this->info("Accounts checked: {$checked}. Failed: {$failed}.");

        return self::SUCCESS;
    }
}
