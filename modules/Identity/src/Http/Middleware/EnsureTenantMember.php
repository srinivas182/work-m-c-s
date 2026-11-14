<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\Membership;
use Wurk\Platform\Contracts\SupportSessions;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Business back office: the signed-in person must be an active team member (or WURK support the owner let in).
 * Non-members get the same 404 as an unknown business, so nothing leaks.
 */
final readonly class EnsureTenantMember
{
    public function __construct(private TenantContext $context, private SupportSessions $support) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->user();

        $member = $identity instanceof Identity && Membership::query()
            ->where('identity_id', $identity->id)
            ->where('tenant_id', $this->context->id())
            ->where('kind', Membership::KIND_STAFF)
            ->where('status', 'active')
            ->exists();

        // Or WURK support, let in by the owner for now (Sprint 16.4).
        $member = $member || ($identity instanceof Identity && $this->support->active($this->context->id(), (string) $identity->id) !== null);
        abort_unless($member, 404);

        return $next($request);
    }
}
