<?php

declare(strict_types=1);

namespace Wurk\Payments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Payments\Models\PlatformPaymentAttempt;
use Wurk\Payments\Support\PlatformMethods;
use Wurk\Payments\Support\WurkPayments;

/** Payments to WURK: the hand-off page for form-posting providers, and provider notifications. */
final class WurkPaymentsController
{
    public function pay(string $reference): Response
    {
        $attempt = PlatformPaymentAttempt::query()->findOrFail($reference);
        abort_if($attempt->status !== 'pending' || $attempt->form === null, 404);
        $action = $attempt->form['action'];
        $html = view('payments::handoff', ['business' => 'WURK', 'provider' => PlatformMethods::NAMES[$attempt->provider] ?? 'payment', 'action' => $action, 'fields' => $attempt->form['fields']])->render();

        return new Response($html, 200, ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-".base64_encode(hash('sha256', 'document.forms[0].submit();', true))."'; form-action {$action}; base-uri 'none'; frame-ancestors 'none'",
            'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
    }

    public function notify(Request $request, string $provider, WurkPayments $payments): Response
    {
        if (! in_array($provider, PlatformMethods::ONLINE, true)) {
            return new Response('', 404);
        }
        $posted = [];
        foreach (explode('&', $request->getContent()) as $pair) { // names exactly as sent (see PublicPaymentsController)
            if ($pair !== '' && str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                if (! str_contains(urldecode($k), '[')) {
                    $posted[urldecode($k)] = urldecode($v);
                }
            }
        }
        $headers = array_map(fn ($v) => (string) ($v[0] ?? ''), $request->headers->all());

        return new Response('', $payments->notified($provider, $request->getContent(), $posted, $headers) ? 200 : 400);
    }
}
