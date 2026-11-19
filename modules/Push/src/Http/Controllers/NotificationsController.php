<?php

declare(strict_types=1);

namespace Wurk\Push\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Push\Jobs\SendPush;
use Wurk\Push\Models\PushSubscription;
use Wurk\Push\Support\Notifier;
use Wurk\Push\Support\WebPush;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Settings → Notifications on my phone (Sprint 26.2): turn push on for this phone, choose what to be told about in
 * this business, and send a test. Every team member manages their own.
 */
final class NotificationsController
{
    public function __construct(private Notifier $notifier, private TenantContext $context, private AuditLog $audit) {}

    public function show(Request $request, WebPush $push): Response
    {
        $me = (string) $request->user()?->getAuthIdentifier();

        return Inertia::render('Push::Settings', [
            'publicKey' => $push->publicKey(),
            'kinds' => array_map(fn (string $k, string $label) => ['key' => $k, 'label' => __($label)], array_keys(PushNotifications::KINDS), PushNotifications::KINDS),
            'off' => $this->notifier->off($me),
            'phones' => PushSubscription::query()->where('identity_id', $me)->count(),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $d = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{80,100}$/'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,30}$/'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);
        $me = (string) $request->user()?->getAuthIdentifier();
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', $d['endpoint'])], ['identity_id' => $me, 'endpoint' => $d['endpoint'],
            'p256dh' => $d['keys']['p256dh'], 'auth' => $d['keys']['auth'], 'device' => isset($d['device']) ? mb_substr(strip_tags($d['device']), 0, 120) : null]);
        $this->audit->record('push.phone_added', 'identity', $me, ['device' => $d['device'] ?? null]);

        return response()->json(['ok' => true, 'phones' => PushSubscription::query()->where('identity_id', $me)->count()]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $d = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        $me = (string) $request->user()?->getAuthIdentifier();
        PushSubscription::query()->where('endpoint_hash', hash('sha256', $d['endpoint']))->where('identity_id', $me)->delete();

        return response()->json(['ok' => true, 'phones' => PushSubscription::query()->where('identity_id', $me)->count()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate(['off' => ['array'], 'off.*' => ['string', 'in:'.implode(',', array_keys(PushNotifications::KINDS))]]);
        $this->notifier->saveOff((string) $request->user()?->getAuthIdentifier(), array_values($d['off'] ?? []));

        return back()->with('status', 'Saved.');
    }

    /** A test notification to this person's phones. */
    public function test(Request $request): RedirectResponse
    {
        $me = (string) $request->user()?->getAuthIdentifier();
        $payload = (string) json_encode(['title' => __('Notifications are on'), 'body' => __('This is how WURK tells you about :business.', ['business' => $this->context->current()->displayName()]),
            'url' => route('tenant.push.settings'), 'tag' => 'test'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $n = 0;
        foreach (PushSubscription::query()->where('identity_id', $me)->pluck('id') as $id) {
            SendPush::dispatch((string) $id, $payload);
            $n++;
        }

        return back()->with($n > 0 ? 'status' : 'warning', $n > 0 ? 'Sent. It should arrive in a few seconds.' : 'Turn notifications on for this phone first.');
    }
}
