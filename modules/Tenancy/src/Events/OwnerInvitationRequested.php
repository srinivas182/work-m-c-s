<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Events;

/** WURK staff created a business and want its owner invited (by email or mobile number). */
final readonly class OwnerInvitationRequested
{
    public function __construct(
        public string $tenantId,
        public string $businessName,
        public string $contact,
        public string $firstName,
        public string $lastName,
        public ?string $invitedBy,
    ) {}
}
