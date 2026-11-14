<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->signIn = 'http://'.config('wurk.domains.app').'/sign-in';
});

it('sends a nonce-based Content Security Policy on back-office pages, and the page’s own scripts carry the nonce', function () {
    $r = $this->get($this->signIn)->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups')
        ->assertHeaderMissing('Strict-Transport-Security');                       // only over HTTPS
    $csp = (string) $r->headers->get('Content-Security-Policy');
    expect(preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $m))->toBe(1)
        ->and($csp)->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")->toContain("form-action 'self'")
        ->not->toContain('unsafe-eval')->not->toContain("script-src 'self' 'unsafe-inline'");
    // Every <script> on the page (the theme script, Vite's tags) carries this request's nonce.
    preg_match_all('/<script\b[^>]*>/', (string) $r->getContent(), $tags);
    $executable = array_filter($tags[0], fn ($t) => ! str_contains($t, 'application/json'));
    expect($executable)->not->toBeEmpty();
    foreach ($executable as $tag) {
        expect($tag)->toContain('nonce="'.$m[1].'"');
    }
    // A new nonce every request.
    expect((string) $this->get($this->signIn)->headers->get('Content-Security-Policy'))->not->toContain($m[1]);
});

it('adds HSTS over HTTPS — subdomains included only on WURK’s own domain', function () {
    $r = $this->get('https://'.config('wurk.domains.app').'/sign-in')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    expect((string) $r->headers->get('Content-Security-Policy'))->toContain('upgrade-insecure-requests');
    Route::get('/__sec/plain', fn () => 'ok');
    $this->get('https://glow-salon.co.za/__sec/plain')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('keeps a page’s own policy, gives JSON the basics without a CSP, and can report-only or switch off', function () {
    Route::get('/__sec/own', fn () => response('<p>x</p>', 200, ['Content-Security-Policy' => "default-src 'none'", 'X-Frame-Options' => 'SAMEORIGIN']));
    Route::get('/__sec/json', fn () => response()->json(['ok' => true]));
    $this->get('/__sec/own')->assertHeader('Content-Security-Policy', "default-src 'none'")->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $this->getJson('/__sec/json')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderMissing('Content-Security-Policy');

    config(['wurk.security.csp' => 'report-only']);
    $this->get($this->signIn)->assertHeaderMissing('Content-Security-Policy')->assertHeader('Content-Security-Policy-Report-Only');
    config(['wurk.security.csp' => 'off']);
    $this->get($this->signIn)->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only')->assertHeader('X-Frame-Options', 'DENY');
});

it('allows uploaded files from where they are stored, and assets from a CDN', function () {
    config(['app.asset_url' => 'https://cdn.wurk.co.za', 'filesystems.disks.media' => ['driver' => 's3', 'bucket' => 'wurk-media', 'region' => 'af-south-1', 'url' => 'https://media.wurk.co.za']]);
    $csp = (string) $this->get($this->signIn)->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self' https://cdn.wurk.co.za 'nonce-")
        ->toContain('img-src \'self\' data: blob: https://cdn.wurk.co.za https://media.wurk.co.za https://wurk-media.s3.af-south-1.amazonaws.com')
        ->toContain("connect-src 'self' https://media.wurk.co.za https://wurk-media.s3.af-south-1.amazonaws.com");
});

it('no longer serves private files at /storage/{path}', function () {
    expect(Route::has('storage.local'))->toBeFalse()->and(Route::has('storage.local.upload'))->toBeFalse();
});

it('trusts the load balancer’s forwarded headers only when TRUSTED_PROXIES says so', function () {
    Route::get('/__sec/ip', fn (Request $r) => $r->ip().'|'.($r->isSecure() ? 'https' : 'http'));
    $forwarded = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '41.13.7.9', 'HTTP_X_FORWARDED_PROTO' => 'https'];
    expect($this->call('GET', '/__sec/ip', server: $forwarded)->getContent())->toBe('10.0.0.5|http');   // nobody trusted by default

    TrustProxies::at(['10.0.0.0/8']);
    try {
        expect($this->call('GET', '/__sec/ip', server: $forwarded)->getContent())->toBe('41.13.7.9|https');
    } finally {
        TrustProxies::flushState();
    }
});

it('publishes security.txt on WURK’s own addresses, never stale, and the disclosure and Trust pages', function () {
    foreach (['root', 'app', 'admin'] as $d) {
        $r = $this->get('http://'.config("wurk.domains.{$d}").'/.well-known/security.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        expect((string) $r->getContent())->toContain('Contact: mailto:security@wurk.co.za')->toContain('Policy: https://'.config('wurk.domains.root').'/security')
            ->toContain('Canonical: https://'.config("wurk.domains.{$d}").'/.well-known/security.txt');
        preg_match('/Expires: (\S+)/', (string) $r->getContent(), $m);
        expect(CarbonImmutable::parse($m[1])->isBetween(now()->addDays(150), now()->addDays(365)))->toBeTrue();
    }
    $root = 'http://'.config('wurk.domains.root');
    $this->get("{$root}/security")->assertOk()->assertInertia(fn ($p) => $p->component('Marketing::Security')->where('contact', 'security@wurk.co.za'));
    $this->get("{$root}/trust")->assertOk()->assertInertia(fn ($p) => $p->component('Marketing::Trust'));
});
