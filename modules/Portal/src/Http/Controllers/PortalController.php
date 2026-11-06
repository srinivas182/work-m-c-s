<?php

declare(strict_types=1);

namespace Wurk\Portal\Http\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Contracts\Verification;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Portal\Support\CustomerSession;
use Wurk\Referrals\Contracts\CustomerRewards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** {business}/account: customers sign in with a code, agree to POPIA once, and see their portal. */
final class PortalController
{
    private const PENDING = 'portal.contact'; // the address a code was sent to (never shown back unmasked)

    public function __construct(private TenantDirectory $tenants, private TenantContext $context) {}

    public function show(Request $request, string $handle): Response
    {
        return $this->response($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request);
            $business = $this->context->current()->displayName();
            if ($me === null) {
                return Inertia::render('Portal::SignIn', ['business' => $business, 'sent' => $request->session()->get('portal.masked')]);
            }
            if (! $me['consented']) {
                return Inertia::render('Portal::Consent', ['business' => $business]);
            }

            $tz = $this->context->current()->timezone();
            $rewards = app(CustomerRewards::class)->forContact($me['contact_id']);

            return Inertia::render('Portal::Home', [
                'business' => $business,
                'name' => app(ContactBook::class)->names([$me['contact_id']])[$me['contact_id']] ?? '',
                'rewards' => $rewards === null ? null : [...$rewards, 'referred' => array_map(fn (array $r) => ['name' => $r['name'],
                    'since' => CarbonImmutable::instance($r['since'])->setTimezone($tz)->format('j M Y')], $rewards['referred'])],
                // Their own purchases and visits — never internal entries (forms, chats).
                'history' => array_map(fn (array $h) => ['type' => $h['type'], 'summary' => $h['summary'], 'at' => CarbonImmutable::instance($h['at'])->setTimezone($tz)->format('j M Y')],
                    app(ContactBook::class)->history($me['contact_id'], ['order', 'booking', 'class', 'sale'])),
            ]);
        });
    }

    public function code(Request $request, string $handle): RedirectResponse
    {
        return $this->redirect($handle, function () use ($request) {
            $contact = trim((string) $request->validate(['contact' => ['required', 'string', 'max:120']], ['contact.required' => 'Enter your phone number or email.'])['contact']);
            $sent = app(Verification::class)->send($contact, 'portal', $request->ip()); // its errors (bad address, too many) show on the form
            $request->session()->put(self::PENDING, $contact);
            $request->session()->put('portal.masked', $sent['masked']);

            return back()->with('status', 'We sent a code to '.$sent['masked'].'.');
        });
    }

    public function verify(Request $request, string $handle): RedirectResponse
    {
        return $this->redirect($handle, function () use ($request) {
            $d = $request->validate(['code' => ['required', 'string', 'max:10'], 'name' => ['nullable', 'string', 'max:80']], ['code.required' => 'Enter the code we sent you.']);
            $contact = (string) $request->session()->get(self::PENDING, '');
            if ($contact === '') {
                throw ValidationException::withMessages(['code' => 'That code isn’t right, or it has expired. Ask for a new one.']);
            }
            $book = app(ContactBook::class);
            $isEmail = str_contains($contact, '@');
            $known = $book->find($isEmail ? null : $contact, $isEmail ? $contact : null);
            $name = trim((string) ($d['name'] ?? '')) ?: ($known['name'] ?? '');
            // Ask for the name BEFORE checking the code: checking uses it up, so asking afterwards would spend it.
            if ($name === '') {
                $request->session()->put('portal.need_name', true);
                throw ValidationException::withMessages(['name' => 'Tell us your name to finish signing in.']);
            }
            if (! app(Verification::class)->confirm($contact, trim($d['code']), 'portal')) {
                throw ValidationException::withMessages(['code' => 'That code isn’t right, or it has expired. Ask for a new one.']);
            }
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $identity = app(Accounts::class)->forVerifiedContact($contact, $first, $last);
            $contactId = $known['id'] ?? $book->remember($name, $isEmail ? null : $contact, $isEmail ? $contact : null, 'portal', $identity, null, 'Signed in to the portal');
            // Sign in first: it starts a fresh session (against session fixation), so this business is marked after it.
            $redirect = app(Accounts::class)->signIn($request, $identity, '/account');
            app(CustomerSession::class)->start($request, $identity, $contactId);
            $request->session()->forget([self::PENDING, 'portal.masked', 'portal.need_name']);
            app(AuditLog::class)->record('portal.signed_in', 'portal_customer', $identity, []);

            return $redirect;
        });
    }

    public function consent(Request $request, string $handle): RedirectResponse
    {
        return $this->redirect($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request) ?? abort(403);
            $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Please agree to continue.']);
            app(CustomerSession::class)->consent($me['id']);
            app(AuditLog::class)->record('portal.consented', 'portal_customer', $me['id'], []);

            return redirect('/account');
        });
    }

    /** The signed-in customer's own referral link as a QR code (SVG) — to show at the counter or share. */
    public function qr(Request $request, string $handle): \Symfony\Component\HttpFoundation\Response
    {
        $svg = $this->in($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request) ?? abort(404);
            $rewards = app(CustomerRewards::class)->forContact($me['contact_id']) ?? abort(404);

            return (new Writer(new ImageRenderer(new RendererStyle(320, 2), new SvgImageBackEnd)))->writeString($rewards['link']);
        });

        return response((string) $svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store']);
    }

    /** For the site's checkout: the signed-in customer's usable wallet and a short-lived pass to spend it. */
    public function walletPass(Request $request, string $handle): JsonResponse
    {
        $data = $this->in($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request);
            if ($me === null || ! $me['consented']) {
                return ['signed_in' => false];
            }
            $credit = app(StoreCredit::class);

            $total = max(0, min(100_000_000, (int) $request->query('total', '0')));

            return ['signed_in' => true, 'available_cents' => $credit->available($me['contact_id']), 'usable_cents' => $total > 0 ? $credit->usableFor($me['contact_id'], $total) : 0,
                'pass' => $credit->passFor($me['contact_id'])];
        });

        return (new JsonResponse($data))->header('Cache-Control', 'private, no-store');
    }

    public function signOut(Request $request, string $handle): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/account');
    }

    /** @param  \Closure(): mixed  $fn */
    private function in(string $handle, \Closure $fn): mixed
    {
        $tenant = $this->tenants->findByHandle($handle);
        abort_if($tenant === null || ! $tenant->isActive(), 404);

        return $this->context->run($tenant, fn () => $fn());
    }

    private function response(string $handle, \Closure $fn): Response
    {
        $r = $this->in($handle, $fn);
        assert($r instanceof Response);

        return $r;
    }

    private function redirect(string $handle, \Closure $fn): RedirectResponse
    {
        $r = $this->in($handle, $fn);
        assert($r instanceof RedirectResponse);

        return $r;
    }
}
