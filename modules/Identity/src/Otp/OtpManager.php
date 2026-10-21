<?php

declare(strict_types=1);

namespace Wurk\Identity\Otp;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Identity\Contracts\OtpChannel;
use Wurk\Identity\Events\OtpRequested;
use Wurk\Identity\Exceptions\OtpThrottled;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\OtpChallenge;
use Wurk\Identity\Support\Destination;

/**
 * Issues and verifies one-time codes (Sprint 1 spec §7.1).
 *
 * Codes are stored only as keyed hashes bound to the destination; responses
 * never reveal whether an account exists.
 */
final readonly class OtpManager
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_RESET = 'reset';

    public function __construct(private Container $container, private Dispatcher $events) {}

    /** @throws OtpThrottled */
    public function request(Destination $destination, string $purpose, ?string $ip): void
    {
        $cfg = (array) config('wurk.identity.otp');
        $hash = $destination->hash();

        $last = OtpChallenge::query()->where('destination_hash', $hash)->where('purpose', $purpose)->latest('created_at')->first();
        $wait = (int) $cfg['resend_after_seconds'] - (int) ($last?->created_at?->diffInSeconds(now(), true) ?? PHP_INT_MAX);
        if ($last !== null && $wait > 0) {
            throw new OtpThrottled($wait);
        }

        foreach (["otp:dest:{$hash}" => (int) $cfg['per_destination_per_hour'], 'otp:ip:'.($ip ?? 'none') => (int) $cfg['per_ip_per_hour']] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new OtpThrottled(RateLimiter::availableIn($key));
            }
        }
        RateLimiter::hit("otp:dest:{$hash}", 3600);
        RateLimiter::hit('otp:ip:'.($ip ?? 'none'), 3600);

        $identity = Identity::query()->where($destination->column(), $destination->value)->first();

        // Supersede earlier codes for the same destination and purpose.
        OtpChallenge::query()->where('destination_hash', $hash)->where('purpose', $purpose)
            ->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 10 ** (int) $cfg['length'] - 1), (int) $cfg['length'], '0', STR_PAD_LEFT);

        OtpChallenge::query()->create([
            'identity_id' => $identity?->id,
            'channel' => $destination->type,
            'destination_hash' => $hash,
            'code_hash' => $this->hashCode($code, $hash),
            'purpose' => $purpose,
            'expires_at' => now()->addSeconds((int) $cfg['ttl_seconds']),
            'ip' => $ip,
        ]);

        // Only send to people who exist and are active; the response is identical either way.
        if ($identity !== null && $identity->isActive()) {
            $this->channelFor($destination)->send($destination->value, $code, $purpose);
        }

        $this->events->dispatch(new OtpRequested($identity, $destination->type, $purpose));
    }

    /** Returns the verified identity, or null if the code is wrong, expired or exhausted. */
    public function verify(Destination $destination, string $code, string $purpose): ?Identity
    {
        $hash = $destination->hash();
        $challenge = OtpChallenge::query()
            ->where('destination_hash', $hash)->where('purpose', $purpose)
            ->whereNull('consumed_at')->where('expires_at', '>', now())
            ->latest('created_at')->first();

        if ($challenge === null) {
            return null;
        }

        $challenge->increment('attempts');
        $maxAttempts = (int) config('wurk.identity.otp.max_attempts', 5);
        $valid = hash_equals($challenge->code_hash, $this->hashCode(preg_replace('/\D/', '', $code) ?? '', $hash));

        if (! $valid) {
            if ($challenge->attempts >= $maxAttempts) {
                $challenge->forceFill(['consumed_at' => now()])->save();
            }

            return null;
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
        $identity = $challenge->identity_id !== null ? Identity::query()->find($challenge->identity_id) : null;

        if ($identity !== null) {
            $column = $destination->type === 'email' ? 'email_verified_at' : 'phone_verified_at';
            $identity->forceFill([$column => $identity->{$column} ?? now()])->save();
        }

        return $identity?->isActive() ? $identity : null;
    }

    private function hashCode(string $code, string $destinationHash): string
    {
        return hash_hmac('sha256', $code.'|'.$destinationHash, (string) config('app.key'));
    }

    private function channelFor(Destination $destination): OtpChannel
    {
        /** @var class-string<OtpChannel> $class */
        $class = config("wurk.identity.otp.channels.{$destination->type}");

        return $this->container->make($class);
    }
}
