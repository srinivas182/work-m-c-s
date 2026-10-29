<?php

declare(strict_types=1);

namespace Wurk\Shipping\Gateways;

use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Shipping\Models\ShippingAccount;
use Wurk\Shipping\Support\ShippingProblem;

/**
 * Pargo pickup points: the nearest points to an address, and warehouse-to-pickup-point orders, with the
 * business's own Pargo account (staging credentials first). Field names marked "confirm" are to be checked
 * against Pargo's staging API before go-live.
 */
final class Pargo
{
    private static function token(ShippingAccount $account): string
    {
        $c = $account->credentials();
        try {
            $r = Http::acceptJson()->timeout((int) config('shipping.timeout', 15))->post($account->baseUrl().'/auth', ['username' => $c['username'] ?? '', 'password' => $c['password'] ?? '']);
        } catch (Throwable) {
            throw new ShippingProblem('We couldn’t reach Pargo. Please try again.');
        }
        $token = $r->json('access_token'); // confirm
        if (! $r->successful() || ! is_string($token) || $token === '') {
            throw new ShippingProblem('Pargo didn’t accept the username and password.');
        }

        return $token;
    }

    /** @return list<array{code: string, name: string, address: string}> */
    public static function points(ShippingAccount $account, string $near): array
    {
        $token = self::token($account);
        try {
            $r = Http::withToken($token)->acceptJson()->timeout((int) config('shipping.timeout', 15))->get($account->baseUrl().'/pickup_points', ['address' => $near]);
        } catch (Throwable) {
            throw new ShippingProblem('We couldn’t reach Pargo for pickup points. Please try again.');
        }
        $out = [];
        foreach ((array) ($r->json('data') ?? []) as $p) { // confirm: data[].attributes.pargoPointCode / storeName / address1 / city
            $a = is_array($p) ? (array) ($p['attributes'] ?? $p) : [];
            if (! isset($a['pargoPointCode'])) {
                continue;
            }
            $out[] = ['code' => (string) $a['pargoPointCode'], 'name' => (string) ($a['storeName'] ?? $a['pargoPointCode']),
                'address' => trim(implode(', ', array_filter([(string) ($a['address1'] ?? ''), (string) ($a['suburb'] ?? ''), (string) ($a['city'] ?? '')])))];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{tracking_reference: string|null, tracking_url: string|null, label_url: string|null, provider_id: string|null}
     */
    public static function ship(ShippingAccount $account, string $reference, array $details): array
    {
        $token = self::token($account);
        try {
            $r = Http::withToken($token)->acceptJson()->timeout((int) config('shipping.timeout', 15))->post($account->baseUrl().'/orders', ['data' => [
                'type' => 'W2P', 'attributes' => [ // warehouse to pickup point
                    'externalReference' => $reference, 'pickupPointCode' => $details['pickup_point'] ?? null,
                    'consignee' => ['firstName' => $details['name'], 'phoneNumbers' => [$details['phone']], 'email' => $details['email'] ?? null],
                ],
            ]]);
        } catch (Throwable) {
            throw new ShippingProblem('We couldn’t reach Pargo. Please try again.');
        }
        if (! $r->successful()) {
            throw new ShippingProblem('Pargo couldn’t create the order — please check the pickup point and try again.');
        }
        $waybill = $r->json('data.attributes.orderData.trackingCode') ?? $r->json('data.attributes.waybillNumber'); // confirm

        return ['tracking_reference' => $waybill === null ? null : (string) $waybill, 'provider_id' => $r->json('data.id') === null ? null : (string) $r->json('data.id'),
            'tracking_url' => $waybill === null ? null : 'https://pargo.co.za/track-trace/?code='.rawurlencode((string) $waybill), // confirm
            'label_url' => $r->json('data.attributes.orderData.orderLabel') === null ? null : (string) $r->json('data.attributes.orderData.orderLabel')]; // confirm
    }
}
