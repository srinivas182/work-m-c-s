<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Wurk\Identity\Support\Destination;

beforeEach(fn () => config(['wurk.identity.default_country_code' => '27']));

it('normalises South African mobile numbers to E.164', function (string $input) {
    expect(Destination::parse($input)->value)->toBe('+27821234567')
        ->and(Destination::parse($input)->type)->toBe('phone');
})->with(['082 123 4567', '0821234567', '+27 82 123 4567', '27821234567', '0027821234567', '(082) 123-4567']);

it('lower-cases emails', function () {
    expect(Destination::parse('  Thandi@Glow.CO.ZA ')->value)->toBe('thandi@glow.co.za');
});

it('rejects input that is neither an email nor a number', function (string $input) {
    Destination::parse($input);
})->with(['not-an-email@', '12345', 'hello', '+0123'])->throws(ValidationException::class);

it('masks destinations for display', function () {
    expect(Destination::parse('thandi@glow.co.za')->masked())->toBe('t•••••@glow.co.za')
        ->and(Destination::parse('0821234567')->masked())->toBe('+27 ••• ••• 4567');
});

it('hashes destinations so they are never stored in plain text', function () {
    $hash = Destination::parse('thandi@glow.co.za')->hash();
    expect($hash)->toHaveLength(64)->not->toContain('thandi');
});
