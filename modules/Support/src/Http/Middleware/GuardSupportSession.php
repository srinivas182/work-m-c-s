<?php

declare(strict_types=1);

namespace Wurk\Support\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Support\Support\SupportAccess;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Consent-based support access, enforced on every back-office request (Sprint 16.4):
 *  - WURK staff in a business: view-only refuses every change; full refuses sensitive ones; actions are audited as
 *    "WURK staff (on behalf of …)"; a banner says whose back office this is and until when.
 *  - The business's owners: a banner while WURK asks for, or has, access — with the way to answer or end it.
 */
final readonly class GuardSupportSession
{
    public function __construct(private TenantContext $context, private SupportAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $this->context->has() || $user === null) {
            return $next($request);
        }
        $tenant = $this->context->id();
        $session = $this->access->active($tenant, (string) $user->getAuthIdentifier());
        if ($session !== null) {
            $route = (string) $request->route()?->getName();
            $safe = $request->isMethodSafe();
            if (! $safe && $session['level'] === 'view') {
                abort(403, 'WURK support has view-only access here — changes are off.');
            }
            if (! $safe && Str::is(SupportAccess::BLOCKED_ROUTES, $route)) {
                abort(403, 'WURK support can’t do this — money, access and security changes are always the business’s own.');
            }
            Context::add('wurk.on_behalf', ['staff_id' => (string) $user->getAuthIdentifier(), 'label' => "WURK staff: {$session['staff_name']} (on behalf of {$session['business']})"]);
            $request->attributes->set('wurk.banner', [
                'tone' => 'warning',
                'message' => "You’re in {$session['business']}’s back office as WURK support — ".($session['level'] === 'full' ? 'full access' : 'view-only')
                    .' until '.CarbonImmutable::instance($session['ends_at'])->setTimezone($this->context->current()->timezone())->format('H:i').'. Everything you do is logged.',
                'action' => ['label' => 'Back to ticket #'.$session['ticket'], 'href' => route('admin.support.show', ['number' => $session['ticket']])],
            ]);

            return $next($request);
        }

        $current = $request->attributes->has('wurk.banner') || ! Gate::forUser($user)->allows('support.access') ? null : $this->access->current($tenant);
        if ($current !== null) {
            $request->attributes->set('wurk.banner', $current->status === 'requested'
                ? ['tone' => 'info', 'message' => 'WURK support asks for '.($current->requested_level === 'full' ? 'full' : 'view-only').' access to help with your ticket.',
                    'action' => ['label' => 'Review', 'href' => route('tenant.support.access')]]
                : ['tone' => 'warning', 'message' => 'WURK support has '.($current->level === 'full' ? 'full' : 'view-only').' access to your back office until '
                    .$current->ends_at?->setTimezone($this->context->current()->timezone())->format('H:i').'.', 'action' => ['label' => 'End access', 'href' => route('tenant.support.access')]]);
        }

        return $next($request);
    }
}
