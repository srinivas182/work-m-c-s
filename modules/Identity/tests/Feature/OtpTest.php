<?php

declare(strict_types=1);

use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Wurk\Identity\Exceptions\OtpThrottled;
use Wurk\Identity\Models\OtpChallenge;
use Wurk\Identity\Otp\OtpManager;
use Wurk\Identity\Support\Destination;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    $this->useFakeOtpChannel();
    $this->otp = app(OtpManager::class);
    $this->person = $this->person();
    $this->email = Destination::parse('thandi@glow.co.za');
});

it('sends a 6-digit code and stores only a hash', function () {
    $this->otp->request($this->email, 'login', '10.0.0.1');
    $code = FakeOtpChannel::$codes['thandi@glow.co.za'];

    expect($code)->toMatch('/^\d{6}$/')
        ->and(OtpChallenge::query()->value('code_hash'))->not->toContain($code)->toHaveLength(64);
});

it('verifies a correct code once and marks the destination verified', function () {
    $this->otp->request($this->email, 'login', '10.0.0.1');
    $code = FakeOtpChannel::$codes['thandi@glow.co.za'];

    expect($this->otp->verify($this->email, $code, 'login')?->id)->toBe($this->person->id)
        ->and($this->person->fresh()->email_verified_at)->not->toBeNull()
        ->and($this->otp->verify($this->email, $code, 'login'))->toBeNull();
});

it('does not send to unknown people but behaves the same', function () {
    $this->otp->request(Destination::parse('nobody@example.com'), 'login', '10.0.0.1');

    expect(FakeOtpChannel::$sent)->toBe(0)
        ->and(OtpChallenge::query()->count())->toBe(1);
});

it('rejects expired codes', function () {
    $this->otp->request($this->email, 'login', '10.0.0.1');
    $code = FakeOtpChannel::$codes['thandi@glow.co.za'];
    $this->travel(301)->seconds();

    expect($this->otp->verify($this->email, $code, 'login'))->toBeNull();
});

it('burns the code after five wrong attempts', function () {
    $this->otp->request($this->email, 'login', '10.0.0.1');
    $code = FakeOtpChannel::$codes['thandi@glow.co.za'];
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        expect($this->otp->verify($this->email, $wrong, 'login'))->toBeNull();
    }

    expect($this->otp->verify($this->email, $code, 'login'))->toBeNull();
});

it('keeps codes for different purposes apart', function () {
    $this->otp->request($this->email, 'reset', '10.0.0.1');
    $code = FakeOtpChannel::$codes['thandi@glow.co.za'];

    expect($this->otp->verify($this->email, $code, 'login'))->toBeNull();
});

it('makes people wait 30 seconds before resending', function () {
    $this->otp->request($this->email, 'login', '10.0.0.1');
    $this->otp->request($this->email, 'login', '10.0.0.1');
})->throws(OtpThrottled::class);

it('allows at most five codes per destination per hour', function () {
    foreach (range(1, 5) as $_) {
        $this->otp->request($this->email, 'login', '10.0.0.1');
        $this->travel(31)->seconds();
    }

    expect(fn () => $this->otp->request($this->email, 'login', '10.0.0.1'))->toThrow(OtpThrottled::class);
});

it('supersedes the previous code when a new one is sent', function () {
    $this->otp->request($this->email, 'login', '10.0.0.1');
    $first = FakeOtpChannel::$codes['thandi@glow.co.za'];
    $this->travel(31)->seconds();
    $this->otp->request($this->email, 'login', '10.0.0.1');
    $second = FakeOtpChannel::$codes['thandi@glow.co.za'];

    if ($first !== $second) {
        expect($this->otp->verify($this->email, $first, 'login'))->toBeNull();
    }
    expect($this->otp->verify($this->email, $second, 'login'))->not->toBeNull();
});
