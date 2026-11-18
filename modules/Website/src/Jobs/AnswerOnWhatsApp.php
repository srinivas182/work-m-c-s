<?php

declare(strict_types=1);

namespace Wurk\Website\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Support\WhatsAppReceptionist;

/**
 * The AI receptionist answers a WhatsApp message (Sprint 21.3). Queued, so Meta's webhook is acknowledged at once, and
 * a few seconds late on purpose: customers often send several short messages in a row, and only the last one is
 * answered (with all of them in view). One answer at a time per conversation.
 */
final class AnswerOnWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds to wait for the customer's next message before answering. */
    public const SETTLE = 4;

    public int $tries = 2;

    public function __construct(public readonly string $tenantId, public readonly string $conversationId, public readonly string $messageId) {}

    public function handle(TenantDirectory $tenants, TenantContext $context): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function (): void {
            Cache::lock('whatsapp-answer:'.$this->conversationId, 120)->block(60, function (): void {
                $latest = ReceptionistMessage::query()->where('conversation_id', $this->conversationId)->where('role', 'user')->orderByDesc('position')->value('id');
                $c = ReceptionistConversation::query()->find($this->conversationId);
                if ($c !== null && $latest === $this->messageId) { // a newer message has its own job
                    app(WhatsAppReceptionist::class)->answer($c);
                }
            });
        });
    }
}
