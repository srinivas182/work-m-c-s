<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Wurk\Bookings\Contracts\HealthPracticeStatus;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Bookings\Models\MemberRegistration;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Health practices: online consultations only with every condition in place — the practitioner's
 * registration shown, and the patient's consent (fixed, versioned wording) recorded before booking.
 */
final readonly class HealthPractice implements HealthPracticeStatus
{
    /** Bump when the wording changes: what a patient agreed to is never rewritten. */
    public const CONSENT_VERSION = 'v1';

    public const CONSENT = 'I agree to a consultation by video. I understand that a video consultation has limits compared with an in-person visit and that the practitioner may ask me to come in person; that my information is kept confidential; and that I should be somewhere private when we speak.';

    public function __construct(private TenantContext $context) {}

    public function on(): bool
    {
        return (bool) BookingSettings::query()->find($this->context->id())?->health_practice;
    }

    /** "HPCSA MP0123456", or null. */
    public function registration(?string $memberId): ?string
    {
        $r = $memberId === null ? null : MemberRegistration::query()->find($memberId);

        return $r === null ? null : "{$r->council} {$r->number}";
    }

    /**
     * Practitioners who would see patients online without a registration on file.
     *
     * @param  list<string>  $memberIds
     * @return list<string>
     */
    public function unregistered(array $memberIds): array
    {
        $registered = MemberRegistration::query()->whereIn('member_id', $memberIds)->pluck('member_id')->all();

        return array_values(array_diff($memberIds, $registered));
    }
}
