<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Support\VoiceNotes;

/**
 * Daily (Sprint 19.2): AI receptionist conversations that led nowhere are deleted 30 days after their last message,
 * as the customer was told. One that led to a booking, an order or a hand-off is kept with the customer's records.
 * WhatsApp files go with their conversations, and voice notes' audio is deleted after its days (the words stay — 21.4).
 */
final class SweepConversationsCommand extends Command
{
    protected $signature = 'website:sweep-conversations';

    protected $description = 'Delete AI receptionist conversations that led nowhere after 30 days, and old voice notes’ audio';

    public function handle(TenantDirectory $directory, TenantContext $context): int
    {
        $deleted = 0;
        $expired = 0;
        foreach ($directory->activeIds() as $id) {
            $tenant = $directory->find($id);
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use (&$deleted, &$expired): void {
                $old = ReceptionistConversation::query()->where('keep', false)->where('last_message_at', '<', now()->subDays(ReceptionistConversation::KEEP_DAYS));
                ReceptionistMessage::query()->whereIn('conversation_id', (clone $old)->select('id'))->whereNotNull('attachment')->get()
                    ->each(fn (ReceptionistMessage $m) => is_string($m->attachment['path'] ?? null) && Storage::disk('form-files')->delete($m->attachment['path']));
                $deleted += $old->delete(); // messages go with them
                ReceptionistMessage::query()->where('created_at', '<', now()->subDays(VoiceNotes::keepDays()))->whereNotNull('attachment')->get()
                    ->filter(fn (ReceptionistMessage $m) => ($m->attachment['kind'] ?? null) === 'audio')
                    ->each(function (ReceptionistMessage $m) use (&$expired): void {
                        $path = $m->attachment['path'] ?? null;
                        if ($path === null) {
                            return; // already deleted
                        }
                        Storage::disk('form-files')->delete($path);
                        $m->update(['attachment' => [...(array) $m->attachment, 'path' => null, 'expired' => true]]);
                        $expired++;
                    });
            });
        }
        $this->info("Conversations deleted: {$deleted}. Voice notes' audio deleted: {$expired}.");

        return self::SUCCESS;
    }
}
