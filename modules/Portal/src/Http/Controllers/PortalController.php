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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\CustomerIdentity;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Contracts\Verification;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Portal\Support\CustomerSession;
use Wurk\Portal\Support\DefaultPasswords;
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
                return Inertia::render('Portal::SignIn', ['business' => $business, 'sent' => $request->session()->get('portal.masked'), 'passwords' => app(DefaultPasswords::class)->allowed()]);
            }
            if ($request->session()->get('portal.must_set_password') === true) {
                return Inertia::render('Portal::SetPassword', ['business' => $business]);
            }
            if (! $me['consented']) {
                return Inertia::render('Portal::Consent', ['business' => $business]);
            }

            $tz = $this->context->current()->timezone();
            $rewards = app(CustomerRewards::class)->forContact($me['contact_id']);

            $request_ = DB::connection($this->context->connectionName())->table('data_requests')->where('tenant_id', $this->context->id())
                ->where('contact_id', $me['contact_id'])->orderByDesc('created_at')->first();

            return Inertia::render('Portal::Home', [
                'business' => $business,
                'payouts' => (function () use ($me, $tz) {
                    $p = app(CustomerRewards::class)->payouts($me['contact_id']);

                    return [...$p, 'latest' => $p['latest'] === null ? null : [...$p['latest'], 'asked' => CarbonImmutable::instance($p['latest']['asked'])->setTimezone($tz)->format('j M Y')]];
                })(),
                'deletion' => $request_ === null ? null : ['status' => (string) $request_->status, 'reason' => $request_->reason,
                    'asked' => CarbonImmutable::parse((string) $request_->created_at)->setTimezone($tz)->format('j M Y')],
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
            self::audit('portal.signed_in', 'portal_customer', $identity, []);

            return $redirect;
        });
    }

    /** Sign in with a password (when the business allows it): the WURK ID's own, or this business's default password. */
    public function password(Request $request, string $handle): RedirectResponse
    {
        return $this->redirect($handle, function () use ($request) {
            $d = $request->validate(['identifier' => ['required', 'string', 'max:160'], 'password' => ['required', 'string', 'max:128']]);
            $defaults = app(DefaultPasswords::class);
            abort_unless($defaults->allowed(), 404);
            $nope = fn () => ValidationException::withMessages(['password' => 'That email/number and password don’t match.']);
            $book = app(ContactBook::class);
            $isEmail = str_contains($d['identifier'], '@');
            $known = $book->find($isEmail ? null : $d['identifier'], $isEmail ? $d['identifier'] : null);
            $accounts = app(Accounts::class);
            if ($accounts->hasPassword($d['identifier'])) { // their own WURK ID password
                $identity = $accounts->checkPassword($d['identifier'], $d['password'], $request->ip()) ?? throw $nope();
                $known ?? throw ValidationException::withMessages(['password' => 'First time with '.$this->context->current()->displayName().'? Sign in with a code.']);
                $mustSet = false;
            } else { // this business's default password: throttled like WURK ID passwords
                $key = 'portal-pwd:'.$this->context->id().'|'.mb_strtolower($d['identifier']).'|'.$request->ip();
                if (RateLimiter::tooManyAttempts($key, 5)) {
                    throw ValidationException::withMessages(['password' => 'Too many attempts. Try again later, or sign in with a code.']);
                }
                if ($known === null || ! $defaults->redeem($known['id'], $d['password'])) {
                    RateLimiter::hit($key, 900);
                    throw $nope();
                }
                RateLimiter::clear($key);
                [$first, $last] = array_pad(explode(' ', $known['name'], 2), 2, '');
                $identity = $accounts->forVerifiedContact($d['identifier'], $first, $last); // receiving it proved the address
                $mustSet = true;
            }
            $redirect = $accounts->signIn($request, $identity, '/account');
            app(CustomerSession::class)->start($request, $identity, $known['id']);
            $request->session()->put('portal.must_set_password', $mustSet);
            self::audit('portal.signed_in', 'portal_customer', $identity, ['with' => $mustSet ? 'default_password' : 'password']);

            return $redirect;
        });
    }

    /** After a default password: choose your own (WURK's password rules). */
    public function setPassword(Request $request, string $handle): RedirectResponse
    {
        return $this->redirect($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request);
            abort_if($me === null, 403);
            $accounts = app(Accounts::class);
            $d = $request->validate(['password' => [...$accounts->passwordRules(), 'confirmed']]);
            $accounts->setPassword($me['identity_id'], (string) $d['password']);
            $request->session()->forget('portal.must_set_password');
            self::audit('portal.password_set', 'portal_customer', $me['id'], []);

            return redirect('/account')->with('status', 'Your password is set.');
        });
    }

    public function consent(Request $request, string $handle): RedirectResponse
    {
        return $this->redirect($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request) ?? abort(403);
            $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Please agree to continue.']);
            app(CustomerSession::class)->consent($me['id']);
            self::audit('portal.consented', 'portal_customer', $me['id'], []);

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

    /** "Show this at the till": a QR proving who the signed-in customer is, for a few minutes (Sprint 13.6d). */
    public function idQr(Request $request, string $handle): \Symfony\Component\HttpFoundation\Response
    {
        $svg = $this->in($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request);
            abort_if($me === null || ! $me['consented'], 404);

            return (new Writer(new ImageRenderer(new RendererStyle(320, 2), new SvgImageBackEnd)))
                ->writeString(app(CustomerIdentity::class)->tokenFor($me['contact_id']));
        });

        return response((string) $svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store']);
    }

    /** A copy of everything the business holds about the signed-in customer (POPIA right of access). */
    public function myData(Request $request, string $handle): \Symfony\Component\HttpFoundation\Response
    {
        [$data, $file] = $this->in($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request);
            abort_if($me === null || ! $me['consented'], 404);
            $book = app(ContactBook::class);
            $tenant = $this->context->current();
            $tz = $tenant->timezone();
            $at = fn (?\DateTimeInterface $d) => $d === null ? null : CarbonImmutable::instance($d)->setTimezone($tz)->toIso8601String();
            $rewards = app(CustomerRewards::class)->forContact($me['contact_id']);
            $profile = $book->profile($me['contact_id']) ?? [];
            $portal = DB::connection($this->context->connectionName())->table('portal_customers')->where('tenant_id', $this->context->id())->where('id', $me['id'])->first();
            self::audit('portal.data_copy', 'portal_customer', $me['id'], []);

            return [[
                'business' => $tenant->displayName(),
                'generated_at' => $at(now()),
                'you' => ['name' => $profile['name'] ?? null, 'phone' => $profile['phone'] ?? null, 'email' => $profile['email'] ?? null, 'customer_since' => $at($profile['since'] ?? null),
                    'marketing_consent' => ($profile['marketing_consent_at'] ?? null) === null ? null : ['given_at' => $at($profile['marketing_consent_at']), 'how' => $profile['marketing_consent_source'] ?? null]],
                'notes_the_business_keeps_about_you' => ['tags' => $profile['tags'] ?? [], 'notes' => $profile['notes'] ?? null],
                'history' => array_map(fn (array $h) => ['type' => $h['type'], 'summary' => $h['summary'], 'at' => $at($h['at'])], $book->history($me['contact_id'], [], 5000)),
                'referrals' => $rewards === null ? null : ['your_code' => $rewards['code'], 'people_you_referred' => array_map(fn (array $r) => ['first_name' => $r['name'], 'since' => $at($r['since'])], $rewards['referred']),
                    'wallet_cents' => $rewards['wallet_cents'], 'pending_cents' => $rewards['pending_cents'],
                    'movements' => array_map(fn (array $l) => ['at' => $at($l['at']), 'kind' => $l['kind'], 'cents' => $l['cents'], 'available_at' => $at($l['available_at'])], app(CustomerRewards::class)->statement($me['contact_id']))],
                'account' => ['consented_at' => $at($portal?->consented_at === null ? null : CarbonImmutable::parse((string) $portal->consented_at))],
            ], 'my-information-'.$tenant->handle().'.json'];
        });

        return response((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$file.'"', 'Cache-Control' => 'private, no-store']);
    }

    /** Ask the business to delete my information (one open request at a time). */
    public function requestDeletion(Request $request, string $handle): RedirectResponse
    {
        return $this->redirect($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request);
            abort_if($me === null || ! $me['consented'], 403);
            $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Please confirm you understand what deletion means.']);
            $db = DB::connection($this->context->connectionName());
            $open = $db->table('data_requests')->where('tenant_id', $this->context->id())->where('contact_id', $me['contact_id'])->where('status', 'open')->exists();
            if (! $open) {
                $id = (string) Str::ulid();
                $db->table('data_requests')->insert(['id' => $id, 'tenant_id' => $this->context->id(), 'contact_id' => $me['contact_id'], 'identity_id' => $me['identity_id'],
                    'kind' => 'deletion', 'status' => 'open', 'created_at' => now()]);
                self::audit('portal.deletion_requested', 'data_request', $id, []); // no personal details (decision H)
            }

            return redirect('/account')->with('status', 'Your request has been sent to the business. We’ll email you when it’s done.');
        });
    }

    /** Ask the business to pay out my wallet (Sprint 13.7). */
    public function requestPayout(Request $request, string $handle): RedirectResponse
    {
        return $this->redirect($handle, function () use ($request) {
            $me = app(CustomerSession::class)->current($request);
            abort_if($me === null || ! $me['consented'], 403);
            $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'holder' => ['required', 'string', 'max:120'], 'bank' => ['required', 'string', 'max:80'],
                'account' => ['required', 'string', 'regex:/^[0-9 ]{6,20}$/'], 'branch' => ['nullable', 'string', 'max:12']],
                ['account.regex' => 'Enter the account number (digits only).', 'holder.required' => 'Whose account is it?', 'bank.required' => 'Which bank?']);
            try {
                app(CustomerRewards::class)->requestPayout($me['contact_id'], (int) round((float) $d['amount'] * 100),
                    ['holder' => $d['holder'], 'bank' => $d['bank'], 'account' => $d['account'], 'branch' => $d['branch'] ?? null]);
            } catch (\DomainException $e) {
                throw ValidationException::withMessages(['amount' => $e->getMessage()]);
            }

            return redirect('/account')->with('status', 'Payout requested — the business will pay it into your account.');
        });
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

    /**
     * Customers' own actions are logged as "Customer" with their WURK ID number — never their name (decision H): the
     * log is a sealed chain that can't be edited later, so a deletion couldn't remove a name from it.
     *
     * @param  array<string, mixed>  $changes
     */
    private static function audit(string $action, string $subjectType, string $subjectId, array $changes): void
    {
        app(AuditLog::class)->record($action, $subjectType, $subjectId, $changes, ['actor_id' => Auth::id() === null ? null : (string) Auth::id(), 'actor_name' => 'Customer']);
    }
}
