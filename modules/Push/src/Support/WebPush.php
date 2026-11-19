<?php

declare(strict_types=1);

namespace Wurk\Push\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Web Push, as browsers expect it, with no third-party service account:
 * - the message is encrypted for that phone (RFC 8291, "aes128gcm"): only the browser that signed up can read it;
 * - WURK signs each request with its own VAPID key (RFC 8292), generated once and kept encrypted.
 * Built on PHP's OpenSSL (P-256 ECDH, HKDF, AES-128-GCM, ES256).
 */
final class WebPush
{
    /** The DER prefix of a P-256 public key, before its 65-byte point. */
    private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Hours a request's signature is good for (at most 24). */
    private const JWT_HOURS = 12;

    /** WURK's public VAPID key (base64url), for the browser to sign up with. Generated on first use. */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /**
     * Sends one message to one phone. Returns the push service's status (201 sent, 404/410 gone, 0 unreachable).
     */
    public function send(string $endpoint, string $p256dh, string $auth, string $payload, int $ttlSeconds = 3600): int
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || ! is_string($host) || $host === '') {
            return 404; // not a push service address: forget it
        }
        $body = self::encrypt($payload, self::b64d($p256dh), self::b64d($auth));
        $keys = $this->keys();
        $jwt = self::jwt('https://'.$host, 'https://'.config('wurk.domains.root'), $keys['private']);
        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => "vapid t={$jwt}, k={$keys['public']}",
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) $ttlSeconds,
                'Urgency' => 'normal',
            ])->withBody($body, 'application/octet-stream')->post($endpoint);
        } catch (ConnectionException) {
            return 0;
        }

        return $response->status();
    }

    /**
     * RFC 8291: the payload encrypted for the browser's key, in one aes128gcm record (header included).
     *
     * @param  string  $uaPublic  the browser's P-256 point (65 bytes)
     * @param  string  $authSecret  the browser's 16-byte secret
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, ?\OpenSSLAsymmetricKey $ephemeral = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new RuntimeException('Not a browser push key.');
        }
        $ephemeral ??= self::newKey();
        $asPublic = self::point($ephemeral);
        $secret = openssl_pkey_derive(self::publicKeyFromPoint($uaPublic), $ephemeral, 32);
        if ($secret === false) {
            throw new RuntimeException('Key agreement failed.');
        }
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $salt ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    /** RFC 8292: a signed token saying WURK sends to this push service. */
    public static function jwt(string $audience, string $subject, string $privatePem, ?int $now = null): string
    {
        $now ??= time();
        $data = self::b64e((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'
            .self::b64e((string) json_encode(['aud' => $audience, 'exp' => $now + self::JWT_HOURS * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false || ! openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed.');
        }

        return $data.'.'.self::b64e(self::derToRaw($der));
    }

    /** @return array{public: string, private: string} */
    private function keys(): array
    {
        $row = DB::table('push_vapid_keys')->where('id', 1)->first();
        if ($row === null) {
            $key = self::newKey();
            openssl_pkey_export($key, $pem);
            DB::table('push_vapid_keys')->insertOrIgnore(['id' => 1, 'public_key' => self::b64e(self::point($key)), 'private_key' => Crypt::encryptString((string) $pem),
                'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('push_vapid_keys')->where('id', 1)->first() ?? throw new RuntimeException('No VAPID keys.'); // another worker may have made them first
        }

        return ['public' => (string) $row->public_key, 'private' => Crypt::decryptString((string) $row->private_key)];
    }

    public static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('Could not make a key.');
        }

        return $key;
    }

    /** A P-256 key's public point, uncompressed (65 bytes). */
    public static function point(\OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'] ?? throw new RuntimeException('Not an EC key.');

        return "\x04".str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT).str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        $der = (string) hex2bin(self::P256_SPKI).$point;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) ?: throw new RuntimeException('Not a P-256 point.');
    }

    /** An ECDSA signature from DER (what OpenSSL gives) to the 64 raw bytes JWT wants. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7F) : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }

    public static function b64e(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64d(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }
}
