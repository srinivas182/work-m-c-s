<?php

declare(strict_types=1);

namespace Wurk\Payments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Support\Payments;
use Wurk\Payments\Support\PlatformMethods;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Public endpoints on the business's address: PayFast's hand-off page, and provider notifications. */
final class PublicPaymentsController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context) {}

    /** PayFast needs a POST: a small page that sends the customer's browser on with the signed fields. */
    public function pay(string $handle, string $reference): Response
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);

        return $this->context->run($tenant, function () use ($tenant, $reference): Response {
            $attempt = PaymentAttempt::query()->findOrFail($reference);
            abort_if($attempt->status !== 'pending' || $attempt->form === null, 404);
            $action = $attempt->form['action'];
            $html = view('payments::handoff', ['business' => $tenant->displayName(), 'provider' => PlatformMethods::NAMES[$attempt->provider] ?? 'payment', 'action' => $action, 'fields' => $attempt->form['fields']])->render();

            // Its own policy: the form may post to PayFast (and nowhere else).
            return new Response($html, 200, ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-".base64_encode(hash('sha256', 'document.forms[0].submit();', true))."'; form-action {$action}; base-uri 'none'; frame-ancestors 'none'",
                'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
        });
    }

    public function notify(Request $request, string $handle, string $provider): Response
    {
        $tenant = $this->tenants->findByHandle($handle);
        if ($tenant === null || ! in_array($provider, PlatformMethods::ONLINE, true)) {
            return new Response('', 404);
        }
        $headers = array_map(fn ($v) => (string) ($v[0] ?? ''), $request->headers->all());
        // Parsed by hand, in the order sent and with names exactly as sent: PHP's parse_str turns dots into
        // underscores ("result.code" → "result_code"), which would break every Peach signature.
        $posted = [];
        foreach (explode('&', $request->getContent()) as $pair) {
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $key = urldecode($k);
            if (! str_contains($key, '[')) { // only plain fields count
                $posted[$key] = urldecode($v);
            }
        }
        $ok = $this->context->run($tenant, fn () => app(Payments::class)->notified($provider, $request->getContent(), $posted, $headers, $request->query('branch') !== null ? (string) $request->query('branch') : null));

        return new Response('', $ok ? 200 : 400);
    }
}
