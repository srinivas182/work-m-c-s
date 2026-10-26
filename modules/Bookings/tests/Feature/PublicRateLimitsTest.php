<?php

declare(strict_types=1);

use Tests\Support\InteractsWithShards;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->createTenant('glow-salon');
    $this->createTenant('the-bistro');
    $this->on = fn (string $handle, string $path) => 'http://'.$handle.'.'.config('wurk.domains.root').$path;
});

it('limits public bookings per business and visitor — never across all of WURK', function () {
    foreach (range(1, 10) as $i) {
        expect($this->postJson(($this->on)('glow-salon', '/book'), [])->status())->not->toBe(429);
    }
    $this->postJson(($this->on)('glow-salon', '/book'), [])->assertStatus(429);
    // The same visitor (one shared mobile IP, say) can still book with another business.
    expect($this->postJson(($this->on)('the-bistro', '/book/tables'), [])->status())->not->toBe(429);
});

it('limits quote forms per business and visitor', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson(($this->on)('glow-salon', '/forms/quote'), []);
    }
    $this->postJson(($this->on)('glow-salon', '/forms/quote'), [])->assertStatus(429);
    expect($this->postJson(($this->on)('the-bistro', '/forms/quote'), [])->status())->not->toBe(429);
});
