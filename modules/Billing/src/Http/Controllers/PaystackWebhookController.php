<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Wurk\Billing\Contracts\DepositPaid;
use Wurk\Billing\Gateways\PaymentGateway;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\Payments;

/**
 * Paystack charge.success. The signature is checked, each event is handled
 * once, and the charge is re-confirmed with Paystack's verify API before
 * anything changes. Unknown references are ignored (not our payment).
 */
final class PaystackWebhookController
{
    public function __invoke(Request $request, PaymentGateway $gateway, Payments $payments): Response
    {
        $event = $gateway->parseWebhook($request->getContent(), ['x-paystack-signature' => (string) $request->header('x-paystack-signature')]);
        if ($event === null) {
            return new Response('', 400);
        }

        $fresh = DB::table('gateway_events')->insertOrIgnore(['id' => $event['id'], 'gateway' => 'paystack', 'type' => $event['type'], 'received_at' => now()]) === 1;
        if ($fresh && Payment::query()->where('reference', $event['reference'])->exists()) {
            $payments->confirm($event['reference']);
        } elseif ($fresh && $event['type'] === 'charge.success' && str_starts_with((string) $event['reference'], 'dep_')) {
            event(new DepositPaid((string) $event['reference'])); // a booking deposit (Bookings verifies it)
        }

        return new Response('', 200);
    }
}
