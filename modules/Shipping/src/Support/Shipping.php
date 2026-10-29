<?php

declare(strict_types=1);

namespace Wurk\Shipping\Support;

use Wurk\Audit\Contracts\AuditLog;
use Wurk\Shipping\Contracts\Couriers;
use Wurk\Shipping\Contracts\ShippingProblem;
use Wurk\Shipping\Gateways\BobGo;
use Wurk\Shipping\Gateways\Pargo;
use Wurk\Shipping\Models\Shipment;
use Wurk\Shipping\Models\ShippingAccount;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class Shipping implements Couriers
{
    public function __construct(private TenantContext $context, private BranchDirectory $branches, private AuditLog $audit) {}

    /** A branch's own account (on, with keys for its mode), else the business's. */
    public function account(string $provider, ?string $branchId): ?ShippingAccount
    {
        $ready = fn (?ShippingAccount $a) => $a !== null && $a->active && $a->hasKeys($a->mode) ? $a : null;
        $own = $branchId === null ? null : $ready(ShippingAccount::query()->where('provider', $provider)->where('branch_id', $branchId)->first());

        return $own ?? $ready(ShippingAccount::query()->where('provider', $provider)->whereNull('branch_id')->first());
    }

    public function options(?string $branchId): array
    {
        $pargo = $this->account('pargo', $branchId);

        return ['courier' => $this->account('bobgo', $branchId) !== null, 'pickup_points' => $pargo !== null, 'pickup_rate_cents' => $pargo?->flat_rate_cents];
    }

    public function rates(?string $branchId, array $to, int $valueCents, float $weightKg): array
    {
        $account = $this->account('bobgo', $branchId) ?? throw new ShippingProblem('Courier delivery isn’t set up.');

        return BobGo::rates($account, $this->from($branchId), $to, $valueCents, $weightKg);
    }

    public function pickupPoints(?string $branchId, string $near): array
    {
        $account = $this->account('pargo', $branchId) ?? throw new ShippingProblem('Pickup points aren’t set up.');

        return Pargo::points($account, $near);
    }

    public function ship(string $reference, ?string $branchId, array $details): array
    {
        $provider = $details['provider'];
        $account = $this->account($provider, $branchId) ?? throw new ShippingProblem($provider === 'pargo' ? 'Pargo isn’t set up.' : 'Bob Go isn’t set up.');
        $result = $provider === 'pargo' ? Pargo::ship($account, $reference, $details) : BobGo::ship($account, $reference, $this->from($branchId), $details);
        Shipment::query()->create([
            'reference' => $reference, 'provider' => $provider, 'account_branch_id' => $account->branch_id, 'service' => $details['service'] ?? null,
            'pickup_point' => $details['pickup_point'] ?? null, 'provider_id' => $result['provider_id'], 'tracking_reference' => $result['tracking_reference'],
            'tracking_url' => $result['tracking_url'], 'label_url' => $result['label_url'], 'status' => 'created',
        ]);
        $this->audit->record('shipping.created', 'shipment', $reference, ['provider' => $provider, 'tracking' => $result['tracking_reference']]);

        return ['tracking_reference' => $result['tracking_reference'], 'tracking_url' => $result['tracking_url'], 'label_url' => $result['label_url']];
    }

    /** @return array<string, string|null> the collection address: the branch's (or the main branch's) */
    private function from(?string $branchId): array
    {
        $branch = collect($this->branches->all())->firstWhere('id', $branchId ?? $this->branches->main()) ?? [];

        return ['company' => $this->context->current()->displayName(), 'street_address' => $branch['address'] ?? null, 'branch' => $branch['name'] ?? null];
    }
}
