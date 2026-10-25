<?php

declare(strict_types=1);

namespace Wurk\Bookings\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Businesses connect their own Google (Meet) or Zoom account. The business travels in an encrypted,
 * expiring `state` that must match a one-time value in the owner's own session — so nobody can attach
 * their account to someone else's business. Tokens are exchanged server-side and stored encrypted.
 */
final class VideoConnectionController
{
    private const PROVIDERS = [
        'google' => ['authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'scope' => 'openid email https://www.googleapis.com/auth/calendar.events'],
        'zoom' => ['authorize' => 'https://zoom.us/oauth/authorize', 'token' => 'https://zoom.us/oauth/token', 'scope' => ''],
    ];

    public function __construct(private ProviderCredentials $providers, private AuditLog $audit) {}

    public function connect(Request $request, TenantContext $context, string $provider): RedirectResponse
    {
        $creds = $this->credentials($provider);
        $nonce = Str::random(40);
        $request->session()->put("video_oauth.{$provider}", $nonce);
        $state = Crypt::encryptString((string) json_encode(['tenant' => $context->id(), 'nonce' => $nonce, 'exp' => now()->addMinutes(10)->getTimestamp()]));
        $query = array_filter([
            'client_id' => $creds['client_id'], 'redirect_uri' => $this->redirectUri($provider), 'response_type' => 'code', 'state' => $state,
            'scope' => self::PROVIDERS[$provider]['scope'] ?: null,
            'access_type' => $provider === 'google' ? 'offline' : null, 'prompt' => $provider === 'google' ? 'consent' : null,
        ]);

        return redirect()->away(self::PROVIDERS[$provider]['authorize'].'?'.http_build_query($query));
    }

    public function callback(Request $request, string $provider, TenantDirectory $tenants, TenantContext $context): RedirectResponse
    {
        $creds = $this->credentials($provider);
        try {
            $state = (array) json_decode(Crypt::decryptString((string) $request->query('state')), true);
        } catch (Throwable) {
            abort(403, 'This connection link isn’t valid. Please try connecting again.');
        }
        $expected = $request->session()->pull("video_oauth.{$provider}");
        if (! is_string($expected) || ! hash_equals($expected, (string) ($state['nonce'] ?? '')) || (int) ($state['exp'] ?? 0) < now()->getTimestamp()) {
            abort(403, 'This connection link has expired or wasn’t started here. Please try connecting again.');
        }
        $tenant = $tenants->find((string) ($state['tenant'] ?? '')) ?? abort(404);
        $settings = '/'.$tenant->handle().'/bookings/settings';
        if ($request->query('error') !== null || ! is_string($request->query('code'))) {
            return redirect($settings)->with('status', 'The video account wasn’t connected.');
        }

        $token = Http::asForm()->timeout(15)->withBasicAuth($creds['client_id'], $creds['client_secret'])->post(self::PROVIDERS[$provider]['token'], [
            'grant_type' => 'authorization_code', 'code' => $request->query('code'), 'redirect_uri' => $this->redirectUri($provider),
            'client_id' => $creds['client_id'], 'client_secret' => $creds['client_secret'],
        ]);
        if (! $token->successful() || ! is_string($token->json('access_token'))) {
            return redirect($settings)->with('status', 'The video account couldn’t be connected. Please try again.');
        }
        $label = $provider === 'google' ? self::emailFromIdToken((string) $token->json('id_token'))
            : (string) Http::withToken((string) $token->json('access_token'))->timeout(15)->get('https://api.zoom.us/v2/users/me')->json('email');

        $context->run($tenant, function () use ($context, $provider, $token, $label): void {
            MeetingConnection::query()->updateOrCreate(['tenant_id' => $context->id()], [
                'provider' => $provider, 'access_token' => (string) $token->json('access_token'), 'refresh_token' => (string) $token->json('refresh_token'),
                'expires_at' => now()->addSeconds((int) $token->json('expires_in', 3600)), 'account_label' => $label ?: null, 'fixed_url' => null,
            ]);
            $this->audit->record('bookings.video_connected', 'meeting_connection', $context->id(), ['provider' => $provider]);
        });

        return redirect($settings)->with('status', ucfirst($provider === 'google' ? 'Google Meet' : 'Zoom').' is connected'.($label ? " ({$label})" : '').'. Online appointments get their own link.');
    }

    public function fixed(Request $request, TenantContext $context): RedirectResponse
    {
        $url = (string) $request->validate(['url' => ['required', 'url:https', 'max:500']], ['url.url' => 'Enter the full meeting link, starting with https://'])['url'];
        MeetingConnection::query()->updateOrCreate(['tenant_id' => $context->id()], ['provider' => 'fixed', 'fixed_url' => $url, 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'account_label' => null]);

        return back()->with('status', 'Your meeting link is saved. Note: every client gets the same link.');
    }

    public function disconnect(TenantContext $context): RedirectResponse
    {
        $connection = MeetingConnection::query()->find($context->id());
        if ($connection?->provider === 'google' && $connection->access_token) {
            try {
                Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $connection->refresh_token ?: $connection->access_token]);
            } catch (Throwable) {
                // Best effort: the tokens are deleted below either way.
            }
        }
        $connection?->delete();
        $this->audit->record('bookings.video_disconnected', 'meeting_connection', $context->id(), []);

        return back()->with('status', 'The video account is disconnected. Online appointments won’t get new links until you connect again.');
    }

    /** @return array{client_id: string, client_secret: string} */
    private function credentials(string $provider): array
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);
        $c = (array) ($this->providers->active('meetings.'.$provider)['credentials'] ?? []);
        abort_if(empty($c['client_id']) || empty($c['client_secret']), 404, 'This video provider isn’t switched on yet.');

        return ['client_id' => (string) $c['client_id'], 'client_secret' => (string) $c['client_secret']];
    }

    private function redirectUri(string $provider): string
    {
        return route('bookings.video.callback', ['provider' => $provider]);
    }

    /** The email in Google's ID token (straight from Google's token endpoint over TLS). */
    private static function emailFromIdToken(string $jwt): string
    {
        $payload = explode('.', $jwt)[1] ?? '';

        return (string) (json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true)['email'] ?? '');
    }
}
