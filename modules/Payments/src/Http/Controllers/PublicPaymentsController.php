<?php

declare(strict_types=1);

namespace Wurk\Payments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Payments\Gateways\PayFast;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Support\Payments;
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
            $account = PaymentAccount::query()->where('provider', 'payfast')->firstOrFail();
            abort_if($attempt->provider !== 'payfast' || $attempt->status !== 'pending' || $attempt->form === null, 404);
            $action = 'https://'.PayFast::host($account).'/eng/process';
            $html = view('payments::payfast', ['business' => $tenant->displayName(), 'action' => $action, 'fields' => $attempt->form])->render();

            // Its own policy: the form may post to PayFast (and nowhere else).
            return new Response($html, 200, ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-".base64_encode(hash('sha256', 'document.forms[0].submit();', true))."'; form-action {$action}; base-uri 'none'; frame-ancestors 'none'",
                'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
        });
    }

    public function notify(Request $request, string $handle, string $provider): Response
    {
        $tenant = $this->tenants->findByHandle($handle);
        if ($tenant === null || ! in_array($provider, ['payfast', 'yoco'], true)) {
            return new Response('', 404);
        }
        $headers = array_map(fn ($v) => (string) ($v[0] ?? ''), $request->headers->all());
        $raw = [];
        parse_str($request->getContent(), $raw); // in the order PayFast sent them (its signature depends on it)
        $posted = [];
        foreach ($raw as $k => $v) {
            if (is_string($v)) { // only plain fields count
                $posted[(string) $k] = $v;
            }
        }
        $ok = $this->context->run($tenant, fn () => app(Payments::class)->notified($provider, $request->getContent(), $posted, $headers));

        return new Response('', $ok ? 200 : 400);
    }
}
