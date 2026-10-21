<?php

declare(strict_types=1);

use Wurk\Identity\Mfa\RecoveryCodes;
use Wurk\Identity\Mfa\Totp;

// RFC 6238 Appendix B test vectors (SHA-1, secret "12345678901234567890"), last 6 digits.
it('matches the RFC 6238 test vectors', function (int $time, string $expected) {
    expect(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $time))->toBe($expected);
})->with([[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037']]);

it('generates 32-character base32 secrets', function () {
    expect(Totp::generateSecret())->toMatch('/^[A-Z2-7]{32}$/');
});

it('accepts one step of clock drift and rejects replays', function () {
    $secret = Totp::generateSecret();
    $now = time();
    $previous = Totp::code($secret, $now - 30);

    expect(Totp::verify($secret, $previous, 'person-1', $now))->toBeTrue()
        ->and(Totp::verify($secret, $previous, 'person-1', $now))->toBeFalse()
        ->and(Totp::verify($secret, Totp::code($secret, $now - 120), 'person-1', $now))->toBeFalse()
        ->and(Totp::verify($secret, 'abc', 'person-1', $now))->toBeFalse();
});

it('builds an otpauth URI and a QR code', function () {
    $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'ops@wurk.co.za');
    expect($uri)->toStartWith('otpauth://totp/WURK%3Aops%40wurk.co.za?secret=JBSWY3DPEHPK3PXP')
        ->and(Totp::qrSvg($uri))->toContain('<svg');
});

it('issues ten single-use recovery codes stored only as hashes', function () {
    $codes = RecoveryCodes::generate();
    expect($codes['plain'])->toHaveCount(10)
        ->and($codes['hashed'][0])->not->toBe($codes['plain'][0]);
});
