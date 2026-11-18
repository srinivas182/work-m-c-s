<?php

declare(strict_types=1);

namespace Wurk\Payments\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Support\MobileMoney;
use Wurk\Payments\Support\Payments;
use Wurk\Payments\Support\PlatformMethods;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Public endpoints on the business's address: PayFast's hand-off page, and provider notifications. */
final class PublicPaymentsController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context) {}

    /**
     * PayFast and Ozow need a POST: a small page that sends the customer's browser on with the signed fields.
     * Mobile money (Sprint 22.2): the page asks for the number, then waits while the customer approves on their phone.
     */
    public function pay(Request $request, string $handle, string $reference): Response|RedirectResponse
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);

        return $this->context->run($tenant, function () use ($request, $tenant, $reference): Response|RedirectResponse {
            $attempt = PaymentAttempt::query()->findOrFail($reference);
            if (in_array($attempt->provider, PlatformMethods::MOBILE, true)) {
                return $this->mobile($request, $tenant->displayName(), $attempt);
            }
            abort_if($attempt->status !== 'pending' || $attempt->form === null, 404);
            $action = $attempt->form['action'];
            $html = view('payments::handoff', ['business' => $tenant->displayName(), 'provider' => PlatformMethods::NAMES[$attempt->provider] ?? 'payment', 'action' => $action, 'fields' => $attempt->form['fields']])->render();

            // Its own policy: the form may post to PayFast (and nowhere else).
            return new Response($html, 200, ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-".base64_encode(hash('sha256', 'document.forms[0].submit();', true))."'; form-action {$action}; base-uri 'none'; frame-ancestors 'none'",
                'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
        });
    }

    /** Mobile money: send the prompt to the number the customer gave, then show the waiting page. */
    public function prompt(Request $request, string $handle, string $reference): Response|RedirectResponse
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);

        return $this->context->run($tenant, function () use ($request, $tenant, $reference): Response|RedirectResponse {
            $attempt = PaymentAttempt::query()->findOrFail($reference);
            abort_unless(in_array($attempt->provider, PlatformMethods::MOBILE, true) && ($attempt->form['mobile'] ?? false) === true, 404);
            if ($attempt->status === 'paid') {
                return new RedirectResponse((string) $attempt->form['return'], 303);
            }
            $failure = app(MobileMoney::class)->prompt($attempt, mb_substr((string) $request->input('phone', ''), 0, 30));
            if ($failure === 'number') {
                return $this->mobile($request, $tenant->displayName(), $attempt->refresh(), 'Enter your mobile money number, e.g. 0712 345 678.');
            }

            return new RedirectResponse($request->fullUrl(), 303); // the same signed address, now waiting
        });
    }

    /** The mobile money page: the number form, the waiting screen (refreshing itself), or the result. */
    private function mobile(Request $request, string $business, PaymentAttempt $attempt, ?string $error = null): Response|RedirectResponse
    {
        abort_unless(($attempt->form['mobile'] ?? false) === true, 404);
        $state = match ($attempt->status) {
            'paid' => 'paid',
            'pending' => $attempt->provider_id === null ? 'ask' : app(MobileMoney::class)->check($attempt)->state,
            default => 'failed',
        };
        if ($state === 'paid') {
            return new RedirectResponse((string) $attempt->form['return'], 303);
        }
        $attempt->refresh();
        $country = $this->context->current()->countryCode();
        $known = MobileMoney::msisdn((string) ($attempt->form['phone'] ?? ''), $country);
        $html = view('payments::mobile-money', [
            'business' => $business, 'provider' => PlatformMethods::NAMES[$attempt->provider],
            'amount' => (MobileMoney::CURRENCIES[$country] ?? 'R').' '.number_format($attempt->amount_cents / 100, 2),
            'state' => $state, 'action' => $request->fullUrl(), 'cancel' => (string) ($attempt->form['cancel'] ?? ''), 'error' => $error,
            'failure' => $state === 'failed' ? MobileMoney::MESSAGES[(string) ($attempt->form['failure'] ?? 'other')] ?? MobileMoney::MESSAGES['other'] : null,
            // Shown as typed locally (0712 345 678), never the full international number in the page's address.
            'phone' => $known === null ? '' : self::local($known),
        ])->render();

        return new Response($html, 200, ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
    }

    private static function local(string $msisdn): string
    {
        foreach (MobileMoney::DIAL as $dial) {
            if (str_starts_with($msisdn, $dial)) {
                return '0'.substr($msisdn, strlen($dial));
            }
        }

        return $msisdn;
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
