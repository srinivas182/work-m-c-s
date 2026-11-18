<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Platform\I18n\Locales;

/*
 * Sprint 24.1: the language for each person and visitor, translations that fall back to English, and rand written the
 * same way on the server and in the browser.
 */

uses(InteractsWithIdentity::class);

beforeEach(function () {
    File::put(lang_path('zu.json'), json_encode(['Language saved.' => 'Ulimi lugciniwe.']));
});

afterEach(fn () => File::delete(lang_path('zu.json')));

it('uses the person’s language, saves a new choice, and loads its translations before the app', function () {
    $me = $this->person();
    $this->actingAs($me)->get($this->app('/account/security'))->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'en')->where('profile.locale', 'en'));
    $this->actingAs($me)->put($this->app('/account/language'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
    $this->actingAs($me)->put($this->app('/account/language'), ['locale' => 'zu'])->assertSessionHas('status', 'Ulimi lugciniwe.');
    expect($me->refresh()->locale)->toBe('zu');

    $page = $this->actingAs($me)->get($this->app('/account/security'));
    $page->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'zu'));
    expect($page->getContent())->toContain('<html lang="zu">')->toContain('/i18n/zu.'.Locales::version('zu').'.js');

    $js = $this->get($this->app('/i18n/zu.'.Locales::version('zu').'.js'))->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    expect($js->getContent())->toBe('window.__wurkI18n={"Language saved.":"Ulimi lugciniwe."};')
        ->and((string) $js->headers->get('Cache-Control'))->toContain('immutable');
    $this->get($this->app('/i18n/en.0.js'))->assertNotFound();
    $this->get($this->app('/i18n/xx.0.js'))->assertNotFound();
});

it('picks a visitor’s language from ?lang=, then the cookie, then the browser, else English', function () {
    $this->get($this->app('/sign-in'), ['Accept-Language' => 'fr-FR,fr;q=0.9'])->assertSee('<html lang="fr">', false);
    $this->get($this->app('/sign-in'), ['Accept-Language' => 'de-DE'])->assertSee('<html lang="en">', false);
    $this->get($this->app('/sign-in?lang=af'))->assertSee('<html lang="af">', false)->assertCookie(Locales::COOKIE, 'af');
});

it('writes rand in each language’s style', function () {
    expect(Locales::money(138000, 'en'))->toBe('R1 380.00')->and(Locales::money(138000, 'zu'))->toBe('R1 380.00')
        ->and(Locales::money(138000, 'fr'))->toBe('1 380,00 R')->and(Locales::money(-5000, 'en'))->toBe('-R50.00');
});
