<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Platform\I18n\Lite;

/*
 * Sprint 24.4: lite mode switches on with the browser's Data Saver or a slow connection, and a person's choice for
 * their phone wins.
 */

uses(InteractsWithIdentity::class);

it('follows Data Saver and a slow connection, unless the person chose', function () {
    $req = fn (array $headers = [], array $cookies = []) => tap(Request::create('/', 'GET', [], $cookies), fn ($r) => $r->headers->add($headers));
    expect(Lite::on($req()))->toBeFalse()
        ->and(Lite::on($req(['Save-Data' => 'on'])))->toBeTrue()
        ->and(Lite::on($req([], ['wurk_slow' => '1'])))->toBeTrue()
        ->and(Lite::on($req(['Save-Data' => 'on'], ['wurk_lite' => 'off'])))->toBeFalse()
        ->and(Lite::on($req([], ['wurk_lite' => 'on'])))->toBeTrue();
});

it('marks the back office as lite, and tells the page what the person chose', function () {
    $me = $this->person();
    $page = $this->actingAs($me)->withHeader('Save-Data', 'on')->get($this->app('/account/security'));
    $page->assertInertia(fn (Assert $p) => $p->where('lite', ['on' => true, 'choice' => 'auto']));
    expect($page->getContent())->toContain('data-lite="1"');
    $this->actingAs($me)->withUnencryptedCookie('wurk_lite', 'off')->withHeader('Save-Data', 'on')->get($this->app('/account/security'))
        ->assertInertia(fn (Assert $p) => $p->where('lite', ['on' => false, 'choice' => 'off']));
});
