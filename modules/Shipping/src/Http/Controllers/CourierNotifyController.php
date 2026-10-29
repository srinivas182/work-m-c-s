<?php

declare(strict_types=1);

namespace Wurk\Shipping\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Shipping\Contracts\ShipmentUpdated;
use Wurk\Shipping\Models\Shipment;
use Wurk\Shipping\Models\ShippingAccount;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Bob Go's tracking updates for a business's (or a branch's) account, verified with that account's webhook secret. */
final class CourierNotifyController
{
    /** Bob Go's statuses → ours (CONFIRM the status names with Bob Go). */
    private const STATUS = ['collected' => 'collected', 'in-transit' => 'in_transit', 'out-for-delivery' => 'in_transit', 'delivered' => 'delivered', 'failed-delivery' => 'failed'];

    public function bobgo(Request $request, string $handle, TenantDirectory $tenants, TenantContext $context): JsonResponse
    {
        $tenant = $tenants->findByHandle($handle) ?? abort(404);

        return $context->run($tenant, function () use ($request, $context): JsonResponse {
            $branch = $request->query('branch');
            $account = ShippingAccount::query()->where('provider', 'bobgo')
                ->when($branch === null || $branch === '', fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', (string) $branch))->first();
            $secret = (string) ($account?->credentials()['webhook_secret'] ?? '');
            $given = (string) $request->header((string) config('shipping.bobgo_signature_header', 'X-Bobgo-Signature'), '');
            if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $given)) {
                return new JsonResponse(['message' => 'Not verified.'], 400); // no secret, or not signed with it
            }
            $tracking = (string) $request->json('tracking_reference', '');
            $status = self::STATUS[(string) $request->json('status', '')] ?? null;
            $shipment = $tracking === '' ? null : Shipment::query()->where('provider', 'bobgo')->where('tracking_reference', $tracking)->first();
            if ($shipment === null || $status === null) {
                return new JsonResponse(['ok' => true]); // not ours, or a status we don't track: acknowledged
            }
            $shipment->update(['status' => $status]);
            event(new ShipmentUpdated($context->id(), $shipment->reference, $status));

            return new JsonResponse(['ok' => true]);
        });
    }
}
