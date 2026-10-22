<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Support\Payments;
use Wurk\Billing\Support\Proration;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: change package or billing period (PRD §11.3). */
final class PackageChangeController
{
    public function __construct(private readonly Catalog $catalog, private readonly TenantContext $context, private readonly Payments $payments) {}

    public function quote(Request $request): JsonResponse
    {
        [$sub, $target] = $this->resolve($request);

        return new JsonResponse(['kind' => $this->kind($sub, $target), 'charge_cents' => $this->quoteFor($sub, $target)['charge_cents']]);
    }

    public function store(Request $request): Response
    {
        [$sub, $target] = $this->resolve($request);
        $tenantId = $this->context->id();

        if (in_array($sub['status'], ['past_due', 'suspended'], true)) {
            throw ValidationException::withMessages(['package' => 'Please pay the outstanding amount before changing package.']);
        }
        if (in_array($sub['status'], ['trial', 'pending_payment'], true)) {
            $this->catalog->changePackage($tenantId, $target['package_id'], $target['period']);

            return back()->with('status', "You’re now on {$target['name']}.");
        }

        $quote = $this->quoteFor($sub, $target);
        if ($quote['kind'] === 'same') {
            return back();
        }
        if ($quote['kind'] === 'scheduled') {
            $this->catalog->scheduleChange($tenantId, $target['package_id'], $target['period']);

            return back()->with('status', "{$target['name']} starts at your next renewal.");
        }
        if ($quote['charge_cents'] === 0) {
            $this->catalog->changePackage($tenantId, $target['package_id'], $target['period']);

            return back()->with('status', "You’re now on {$target['name']}.");
        }

        $details = ['package_id' => $target['package_id'], 'package_name' => $target['name'], 'period' => $target['period'], 'restart_period' => $quote['restart_period']];
        if ($this->payments->defaultMethod($tenantId) !== null) {
            $payment = $this->payments->chargeSaved($tenantId, 'upgrade', $quote['charge_cents'], $details);

            return back()->with($payment->status === 'succeeded' ? 'status' : 'warning', $payment->status === 'succeeded'
                ? "You’re now on {$target['name']}."
                : 'Your card was declined: '.$payment->failure);
        }
        if (BillingAccount::query()->find($tenantId) === null) {
            return back()->with('warning', 'Add your billing email first.');
        }
        $checkout = $this->payments->checkout($tenantId, 'upgrade', $quote['charge_cents'], $details, route('tenant.billing.return'));

        return $checkout['url'] === null ? back()->with('warning', 'Card payments aren’t available right now.') : Inertia::location($checkout['url']);
    }

    public function cancelScheduled(): RedirectResponse
    {
        $this->catalog->scheduleChange($this->context->id(), null, null);

        return back()->with('status', 'Scheduled change cancelled.');
    }

    /** @return array{0: array<string, mixed>, 1: array{package_id: string, key: string, name: string, period_cents: int, monthly_credits: int, period: string}} */
    private function resolve(Request $request): array
    {
        $data = $request->validate(['package_id' => ['required', 'string'], 'period' => ['required', 'in:monthly,yearly']]);
        $sub = $this->catalog->subscriptionOf($this->context->id()) ?? abort(404);
        $target = $this->catalog->priceOf((string) $data['package_id'], (string) $data['period'])
            ?? throw ValidationException::withMessages(['package_id' => 'That package isn’t available.']);

        return [$sub, [...$target, 'period' => (string) $data['period']]];
    }

    /**
     * @param  array<string, mixed>  $sub
     * @param  array{package_id: string, period_cents: int, period: string}  $target
     * @return array{kind: 'upgrade'|'scheduled'|'same', charge_cents: int, restart_period: bool}
     */
    private function quoteFor(array $sub, array $target): array
    {
        if ($sub['package_id'] === $target['package_id'] && $sub['period'] === $target['period']) {
            return ['kind' => 'same', 'charge_cents' => 0, 'restart_period' => false];
        }

        return Proration::quote([
            'period' => (string) $sub['period'], 'period_cents' => (int) $sub['period_cents'],
            'current_period_start' => $sub['current_period_start'], 'current_period_end' => $sub['current_period_end'],
        ], $target['period'], $target['period_cents']);
    }

    /**
     * @param  array<string, mixed>  $sub
     * @param  array{package_id: string, period_cents: int, period: string}  $target
     */
    private function kind(array $sub, array $target): string
    {
        return in_array($sub['status'], ['trial', 'pending_payment'], true) ? 'now' : $this->quoteFor($sub, $target)['kind'];
    }
}
