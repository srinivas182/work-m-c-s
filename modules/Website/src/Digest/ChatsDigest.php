<?php

declare(strict_types=1);

namespace Wurk\Website\Digest;

use Carbon\CarbonImmutable;
use Wurk\Platform\Contracts\OwnerDigestSource;
use Wurk\Website\Models\ChatHandoff;
use Wurk\Website\Models\ReceptionistConversation;

/** The owner's evening summary (21.6): chats waiting for a person (website and WhatsApp). */
final readonly class ChatsDigest implements OwnerDigestSource
{
    public function key(): string
    {
        return 'chat';
    }

    public function figures(CarbonImmutable $today): array
    {
        return ['chats_waiting' => ReceptionistConversation::query()->where('status', 'waiting')->count() + ChatHandoff::query()->where('status', 'new')->count()];
    }

    public function requests(): array
    {
        return [];
    }

    public function decide(string $id, bool $yes, string $identityId, string $name): string
    {
        return '';
    }
}
