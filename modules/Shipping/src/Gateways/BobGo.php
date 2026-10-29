<?php

declare(strict_types=1);

namespace Wurk\Shipping\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Shipping\Models\ShippingAccount;
use Wurk\Shipping\Support\ShippingProblem;

/**
 * Bob Go (many couriers): rates at checkout, shipments and tracking, with the business's API key.
 * Field names marked "confirm" are to be checked against Bob Go's sandbox before go-live.
 */
final class BobGo
{
    private static function http(ShippingAccount $account): PendingRequest
    {
        return Http::withToken((string) ($account->credentials()['api_key'] ?? ''))->acceptJson()->timeout((int) config('shipping.timeout', 15));
    }

    /**
     * @param  array<string, string|null>  $from
     * @param  array<string, string|null>  $to
     * @return list<array{service: string, name: string, cents: int, eta: string|null}>
     */
    public static function rates(ShippingAccount $account, array $from, array $to, int $valueCents, float $weightKg): array
    {
        try {
            $r = self::http($account)->post($account->baseUrl().'/rates-at-checkout', [
                'collection_address' => $from, 'delivery_address' => $to, 'declared_value' => $valueCents / 100,
                'items' => [['description' => 'Order', 'quantity' => 1, 'price' => $valueCents / 100, 'weight' => $weightKg]],
            ]);
        } catch (Throwable) {
            throw new ShippingProblem('We couldn’t reach the courier for rates. Please try again.');
        }
        if (! $r->successful()) {
            throw new ShippingProblem('The courier couldn’t give a rate for this address — please check it.');
        }
        $out = [];
        foreach ((array) ($r->json('rates') ?? []) as $rate) { // confirm: rates[].service_level_code / service_name / total_price
            if (! is_array($rate) || ! isset($rate['service_level_code'], $rate['total_price'])) {
                continue;
            }
            $out[] = ['service' => (string) $rate['service_level_code'], 'name' => (string) ($rate['service_name'] ?? $rate['service_level_code']),
                'cents' => (int) round((float) $rate['total_price'] * 100), 'eta' => isset($rate['max_delivery_date']) ? (string) $rate['max_delivery_date'] : null];
        }

        return $out;
    }

    /**
     * @param  array<string, string|null>  $from
     * @param  array<string, mixed>  $details
     * @return array{tracking_reference: string|null, tracking_url: string|null, label_url: string|null, provider_id: string|null}
     */
    public static function ship(ShippingAccount $account, string $reference, array $from, array $details): array
    {
        try {
            $r = self::http($account)->post($account->baseUrl().'/shipments', [
                'collection_address' => $from, 'delivery_address' => $details['to'] ?? [],
                'delivery_contact' => ['name' => $details['name'], 'mobile_number' => $details['phone'], 'email' => $details['email'] ?? null],
                'parcels' => [['weight' => $details['weight_kg']]], 'declared_value' => $details['value_cents'] / 100,
                'service_level_code' => $details['service'] ?? null, 'custom_order_number' => $reference,
            ]);
        } catch (Throwable) {
            throw new ShippingProblem('We couldn’t reach Bob Go. Please try again.');
        }
        if (! $r->successful()) {
            throw new ShippingProblem('Bob Go couldn’t create the shipment: '.((string) ($r->json('message') ?? 'please check the address and try again.')));
        }
        $tracking = $r->json('tracking_reference'); // confirm

        return ['tracking_reference' => $tracking === null ? null : (string) $tracking, 'provider_id' => $r->json('id') === null ? null : (string) $r->json('id'),
            'tracking_url' => $tracking === null ? null : 'https://track.bobgo.co.za/'.rawurlencode((string) $tracking), // confirm the public tracking address
            'label_url' => $r->json('waybill_url') === null ? null : (string) $r->json('waybill_url')]; // confirm
    }

    /** Check a key works (a cheap authenticated call). */
    public static function check(ShippingAccount $account): bool
    {
        try {
            return self::http($account)->get($account->baseUrl().'/webhooks')->successful(); // confirm: any authenticated GET
        } catch (Throwable) {
            return false;
        }
    }
}
