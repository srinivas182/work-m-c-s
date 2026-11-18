<?php

declare(strict_types=1);

namespace Wurk\Messaging\Http\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Messaging\Support\WhatsAppGraph;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Settings → WhatsApp (Sprint 21.1): the business connects its own WhatsApp number through Meta's Embedded Signup,
 * usually keeping the WhatsApp Business app on the same number (coexistence). As with video accounts, the business
 * travels in an encrypted, expiring `state` that must match a one-time value in the owner's own session.
 */
final class WhatsAppConnectionController
{
    public function __construct(private WhatsAppGraph $graph, private AuditLog $audit) {}

    public function index(TenantContext $context): Response
    {
        $c = WhatsAppConnection::query()->where('tenant_id', $context->id())->first();

        return Inertia::render('Messaging::WhatsApp', [
            'available' => $this->graph->available(),
            'connection' => $c === null || ! $c->active() ? null : [
                'display_phone' => $c->display_phone, 'verified_name' => $c->verified_name, 'coexistence' => $c->coexistence,
                'name_status' => $c->name_status, 'quality' => $c->quality, 'messaging_limit' => $c->messaging_limit,
                'follow_up_status' => $c->templates[WhatsAppGraph::FOLLOW_UP] ?? null,
                'connected_at' => $c->connected_at?->toIso8601String(), 'checked_at' => $c->checked_at?->toIso8601String(),
            ],
            // Click-to-chat (21.5): the link the website's WhatsApp button uses, and a QR code to print.
            'chat' => $c === null || ! $c->active() || ($number = app(WhatsAppBusiness::class)->number()) === null ? null : [
                'text' => app(WhatsAppBusiness::class)->chatText(),
                'link' => $link = 'https://wa.me/'.$number.'?text='.rawurlencode(app(WhatsAppBusiness::class)->chatText()),
                'qr' => (new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd)))->writeString($link),
            ],
            'base' => route('tenant.whatsapp', [], false),
        ]);
    }

    public function connect(Request $request, TenantContext $context): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($this->graph->available(), 404, 'WhatsApp connections aren’t switched on yet.');
        $coexistence = $request->boolean('coexistence', true);
        $nonce = Str::random(40);
        $request->session()->put('whatsapp_signup', $nonce);
        $state = Crypt::encryptString((string) json_encode([
            'tenant' => $context->id(), 'user' => (string) $request->user()?->getAuthIdentifier(), 'nonce' => $nonce,
            'coexistence' => $coexistence, 'exp' => now()->addMinutes(15)->getTimestamp(),
        ]));

        // Inertia::location: a full-page visit to Meta's site, also from an Inertia (XHR) request.
        return Inertia::location($this->graph->signupUrl(route('whatsapp.connected'), $state, $coexistence));
    }

    /** Meta sends the owner back here (one fixed address on the app domain, registered with Meta). */
    public function callback(Request $request, TenantDirectory $tenants, TenantContext $context): RedirectResponse
    {
        try {
            $state = (array) json_decode(Crypt::decryptString((string) $request->query('state')), true);
        } catch (Throwable) {
            abort(403, 'This connection link isn’t valid. Please try connecting again.');
        }
        $expected = $request->session()->pull('whatsapp_signup');
        if (! is_string($expected) || ! hash_equals($expected, (string) ($state['nonce'] ?? '')) || (int) ($state['exp'] ?? 0) < now()->getTimestamp()
            || (string) ($state['user'] ?? '') !== (string) $request->user()?->getAuthIdentifier()) {
            abort(403, 'This connection link has expired or wasn’t started here. Please try connecting again.');
        }
        $tenant = $tenants->find((string) ($state['tenant'] ?? '')) ?? abort(404);
        $settings = '/'.$tenant->handle().'/settings/whatsapp';
        if ($request->query('error') !== null || ! is_string($request->query('code'))) {
            return redirect($settings)->with('status', 'WhatsApp wasn’t connected. You can try again any time.');
        }

        try {
            $token = $this->graph->exchange((string) $request->query('code'), route('whatsapp.connected'));
            $number = null;
            foreach ($this->graph->sharedAccounts($token) as $waba) {
                foreach ($this->graph->phoneNumbers($waba, $token) as $phone) {
                    $number ??= ['waba' => $waba, ...$phone];
                }
            }
            if ($number === null) {
                throw new RuntimeException('No WhatsApp number was shared.');
            }
            $taken = WhatsAppConnection::query()->where('phone_number_id', $number['id'])->where('tenant_id', '!=', $tenant->tenantId())->where('status', 'connected')->exists();
            if ($taken) {
                return redirect($settings)->with('status', 'That WhatsApp number is already connected to another business on WURK.');
            }
            $coexistence = (bool) ($state['coexistence'] ?? true);
            $pin = null;
            if (! $coexistence) { // a new number: register it for the Cloud API with a two-step verification PIN
                $pin = (string) random_int(100000, 999999);
                $this->graph->register($number['id'], $token, $pin);
            }
            $this->graph->subscribe($number['waba'], $token);
            $templates = $this->templates($number['waba'], $token);
        } catch (Throwable $e) {
            report($e);

            return redirect($settings)->with('status', 'WhatsApp couldn’t be connected: '.Str::limit($e->getMessage(), 160).' Please try again.');
        }

        WhatsAppConnection::query()->updateOrCreate(['tenant_id' => $tenant->tenantId()], [
            'waba_id' => $number['waba'], 'phone_number_id' => $number['id'], 'display_phone' => $number['display_phone'],
            'verified_name' => $number['verified_name'], 'quality' => $number['quality'], 'messaging_limit' => $number['messaging_limit'],
            'name_status' => $number['name_status'], 'coexistence' => $coexistence, 'token' => $token, 'pin' => $pin, 'status' => 'connected',
            'templates' => $templates, 'connected_by' => (string) $request->user()?->getAuthIdentifier(), 'connected_at' => now(), 'disconnected_at' => null, 'checked_at' => now(),
        ]);
        $context->run($tenant, fn () => $this->audit->record('messaging.whatsapp_connected', 'whatsapp_connection', $tenant->tenantId(),
            ['phone' => $number['display_phone'], 'coexistence' => $coexistence]));

        return redirect($settings)->with('status', 'WhatsApp is connected'.($number['display_phone'] ? ' ('.$number['display_phone'].')' : '').'. Customers’ messages now come to Chats.');
    }

    /** Checks the number's name, quality and limit with Meta again. */
    public function refresh(TenantContext $context): RedirectResponse
    {
        $c = WhatsAppConnection::query()->where('tenant_id', $context->id())->where('status', 'connected')->firstOrFail();
        try {
            $phone = collect($this->graph->phoneNumbers($c->waba_id, $c->token))->firstWhere('id', $c->phone_number_id);
        } catch (Throwable $e) {
            return back()->with('status', 'Meta didn’t answer: '.Str::limit($e->getMessage(), 160));
        }
        if ($phone === null) {
            return back()->with('status', 'Meta no longer shares this number with WURK. Connect it again.');
        }
        $c->update(['display_phone' => $phone['display_phone'], 'verified_name' => $phone['verified_name'], 'quality' => $phone['quality'],
            'messaging_limit' => $phone['messaging_limit'], 'name_status' => $phone['name_status'], 'checked_at' => now(),
            'templates' => $this->templates($c->waba_id, $c->token) ?? $c->templates]);

        return back()->with('status', 'Checked with Meta just now.');
    }

    /** The message customers' chats start with from the website's button and the QR code. */
    public function chatLink(Request $request, TenantContext $context): RedirectResponse
    {
        $text = trim((string) $request->validate(['text' => ['nullable', 'string', 'max:160']])['text']);
        WhatsAppConnection::query()->where('tenant_id', $context->id())->where('status', 'connected')->firstOrFail()->update(['chat_text' => $text !== '' ? $text : null]);

        return back()->with('status', 'Saved. Your website’s WhatsApp button and QR code use it now.');
    }

    public function disconnect(TenantContext $context): RedirectResponse
    {
        $c = WhatsAppConnection::query()->where('tenant_id', $context->id())->where('status', 'connected')->firstOrFail();
        try {
            $this->graph->unsubscribe($c->waba_id, $c->token);
        } catch (Throwable) {
            // Best effort: WURK forgets the token below either way, so it can't send or read any more.
        }
        $c->update(['status' => 'disconnected', 'token' => '', 'pin' => null, 'disconnected_at' => now()]);
        $this->audit->record('messaging.whatsapp_disconnected', 'whatsapp_connection', $context->id(), ['phone' => $c->display_phone]);

        return back()->with('status', 'WhatsApp is disconnected. Your number keeps working in the WhatsApp Business app.');
    }

    /**
     * Submits (or looks up) WURK's templates. Not fatal: messaging works without them inside the 24-hour window.
     *
     * @return array<string, string>|null
     */
    private function templates(string $wabaId, string $token): ?array
    {
        try {
            return $this->graph->ensureTemplates($wabaId, $token);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
