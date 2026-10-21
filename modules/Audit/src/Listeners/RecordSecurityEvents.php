<?php

declare(strict_types=1);

namespace Wurk\Audit\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Wurk\Access\Events\AccessChanged;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Events\MembershipGranted;
use Wurk\Identity\Events\MfaDisabled;
use Wurk\Identity\Events\MfaEnabled;
use Wurk\Identity\Events\OtpRequested;
use Wurk\Identity\Events\PasswordChanged;
use Wurk\Identity\Events\SessionsRevoked;
use Wurk\Identity\Events\SignedIn;
use Wurk\Identity\Events\SignedOut;
use Wurk\Identity\Events\SignInFailed;
use Wurk\Identity\Events\StaffGranted;
use Wurk\Tenancy\Events\TenantProvisioned;
use Wurk\Tenancy\Events\TenantStatusChanged;

/**
 * Security events from Identity, Access and Tenancy, recorded synchronously
 * (spec §9.2). Identity and business-lifecycle events go on the platform chain;
 * team and role changes inside a business go on that business's chain.
 */
final readonly class RecordSecurityEvents
{
    public function __construct(private AuditLog $audit) {}

    public function subscribe(Dispatcher $events): void
    {
        $platform = fn (string $action, ?string $type, ?string $id, array $changes = [], array $options = []) => $this->audit->record($action, $type, $id, $changes, ['platform' => true, ...$options]);

        $events->listen(SignedIn::class, fn (SignedIn $e) => $platform('identity.signed_in', 'identity', $e->identity->id, ['surface' => $e->surface, 'method' => $e->method], ['actor_id' => $e->identity->id, 'actor_name' => $e->identity->fullName()]));
        $events->listen(SignInFailed::class, fn (SignInFailed $e) => $platform('identity.sign_in_failed', null, null, ['surface' => $e->surface, 'reason' => $e->reason, 'identifier' => substr($e->identifierHash, 0, 12)]));
        $events->listen(OtpRequested::class, fn (OtpRequested $e) => $platform('identity.code_requested', $e->identity ? 'identity' : null, $e->identity?->id, ['channel' => $e->channel, 'purpose' => $e->purpose, 'known' => $e->identity !== null], $e->identity ? ['actor_id' => $e->identity->id, 'actor_name' => $e->identity->fullName()] : []));
        $events->listen(MfaEnabled::class, fn (MfaEnabled $e) => $platform('identity.mfa_enabled', 'identity', $e->identity->id, [], ['actor_id' => $e->identity->id, 'actor_name' => $e->identity->fullName()]));
        $events->listen(MfaDisabled::class, fn (MfaDisabled $e) => $platform('identity.mfa_disabled', 'identity', $e->identity->id));
        $events->listen(PasswordChanged::class, fn (PasswordChanged $e) => $platform('identity.password_changed', 'identity', $e->identity->id, [], ['actor_id' => $e->identity->id, 'actor_name' => $e->identity->fullName()]));
        $events->listen(SessionsRevoked::class, fn (SessionsRevoked $e) => $platform('identity.sessions_revoked', 'identity', $e->identity->id, ['count' => $e->count]));
        $events->listen(SignedOut::class, fn (SignedOut $e) => $platform('identity.signed_out', 'identity', $e->identity->id, [], ['actor_id' => $e->identity->id, 'actor_name' => $e->identity->fullName()]));
        $events->listen(StaffGranted::class, fn (StaffGranted $e) => $platform('identity.staff_granted', 'identity', $e->identityId, ['role' => $e->role]));
        $events->listen(MembershipGranted::class, fn (MembershipGranted $e) => $platform('identity.membership_granted', 'identity', $e->identityId, ['tenant_id' => $e->tenantId, 'kind' => $e->kind, 'role' => $e->role]));

        $events->listen(TenantProvisioned::class, fn (TenantProvisioned $e) => $platform('tenant.created', 'tenant', $e->tenantId, ['handle' => $e->handle, 'name' => $e->name]));
        $events->listen(TenantStatusChanged::class, fn (TenantStatusChanged $e) => $platform('tenant.status_changed', 'tenant', $e->tenantId, ['handle' => $e->handle, 'from' => $e->from, 'to' => $e->to]));

        $events->listen(AccessChanged::class, fn (AccessChanged $e) => $this->audit->record($e->action, $e->subjectType, $e->subjectId, $e->details, ['platform' => $e->platform]));
    }
}
