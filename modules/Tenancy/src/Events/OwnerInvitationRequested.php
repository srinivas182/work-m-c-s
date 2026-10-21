<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Events;

/** WURK staff created a business and want its owner invited. */
final readonly class OwnerInvitationRequested
{
    public function __construct(
        public string $tenantId,
        public string $businessName,
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $invitedBy,
    ) {}
}
