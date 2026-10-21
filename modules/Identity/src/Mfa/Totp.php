<?php

declare(strict_types=1);

namespace Wurk\Identity\Mfa;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;

/**
 * RFC 6238 time-based one-time passwords (authenticator apps), with replay
 * protection: each code can be used once.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD = 30;

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $secret;
    }

    public static function code(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, self::PERIOD);
        $hmac = hash_hmac('sha1', pack('N2', 0, $counter), self::decode($secret), true);
        $offset = ord($hmac[19]) & 0x0F;
        $value = ((ord($hmac[$offset]) & 0x7F) << 24) | (ord($hmac[$offset + 1]) << 16) | (ord($hmac[$offset + 2]) << 8) | ord($hmac[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepts the current step and one step either side (clock drift); rejects reuse. */
    public static function verify(string $secret, string $code, string $replayKey, ?int $now = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $now ??= now()->getTimestamp(); // app clock, so tests can travel in time
        foreach ([0, -1, 1] as $step) {
            if (hash_equals(self::code($secret, $now + $step * self::PERIOD), $code)) {
                return Cache::add("mfa:used:{$replayKey}:{$code}", true, self::PERIOD * 3);
            }
        }

        return false;
    }

    public static function uri(string $secret, string $account, string $issuer = 'WURK'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => self::PERIOD,
        ]);
    }

    public static function qrSvg(string $uri): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($uri);
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index !== false) {
                $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
