<?php

declare(strict_types=1);

namespace Wurk\Portal\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Open" from My WURK (Sprint 13.8): a pass to arrive at one business's portal already signed in — signed with the app
 * key, for that business and person, valid 60 seconds, usable once.
 */
final class Handoff
{
    public const SECONDS = 60;

    public function issue(string $identityId, string $tenantId): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode(['i' => $identityId, 't' => $tenantId, 'e' => now()->addSeconds(self::SECONDS)->getTimestamp(), 'n' => Str::random(20)])), '+/', '-_'), '=');

        return $body.'.'.$this->sign($body);
    }

    /** The WURK ID the pass is for — null if forged, altered, expired, for another business, or already used. */
    public function redeem(string $pass, string $tenantId): ?string
    {
        [$body, $sig] = array_pad(explode('.', $pass, 2), 2, '');
        if ($body === '' || ! hash_equals($this->sign($body), $sig)) {
            return null;
        }
        $d = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (! is_array($d) || ($d['t'] ?? null) !== $tenantId || (int) ($d['e'] ?? 0) < now()->getTimestamp() || ! is_string($d['i'] ?? null)) {
            return null;
        }

        // Usable once: the first to claim it wins; kept until it would have expired anyway.
        return Cache::add('handoff:'.$d['n'], 1, self::SECONDS + 5) ? $d['i'] : null;
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, 'my-wurk-handoff|'.(string) config('app.key'));
    }
}
