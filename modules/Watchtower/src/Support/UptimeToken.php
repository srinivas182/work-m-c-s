<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Security\Contracts\PlatformSettings;

/**
 * The secret in the uptime checker's webhook address (Sprint 19.6: set in Admin → Watchtower, encrypted; the server's
 * WATCHTOWER_UPTIME_TOKEN only until one is made there). Without either, the webhook doesn't exist.
 */
final readonly class UptimeToken
{
    private const KEY = 'watchtower.uptime_token';

    public function __construct(private PlatformSettings $settings) {}

    public function current(): string
    {
        $saved = $this->settings->get(self::KEY);
        if (is_string($saved) && $saved !== '') {
            try {
                return Crypt::decryptString($saved);
            } catch (Throwable) {
                // an app key change: fall back to the server's, until a new one is made
            }
        }

        return (string) config('wurk.watchtower.uptime_token', '');
    }

    /** A new token: the old address stops working at once (the uptime checker must be given the new one). */
    public function rotate(?string $by): string
    {
        $token = Str::random(40);
        $this->settings->set(self::KEY, Crypt::encryptString($token), $by);

        return $token;
    }

    public function url(?string $token = null): ?string
    {
        $token ??= $this->current();

        return $token === '' ? null : 'https://'.config('wurk.domains.app').'/_wurk/uptime/'.$token;
    }

    /** Where it comes from, for the screen. */
    public function source(): string
    {
        return is_string($this->settings->get(self::KEY)) ? 'settings' : ((string) config('wurk.watchtower.uptime_token', '') !== '' ? 'server' : 'none');
    }
}
