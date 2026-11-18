<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Watchtower\Models\Incident;

uses(InteractsWithIdentity::class);

/** Sprint 19.6: the uptime checker's webhook token is made in Admin → Watchtower, encrypted, instead of only in the server's settings. */
beforeEach(function () {
    $this->engineer = $this->staffWithRole('engineering', 'eng@wurk.co.za');
    $this->page = $this->admin('/watchtower');
    $this->down = ['monitor' => 'app.wurk.co.za', 'status' => 'down', 'cause' => 'Status 503'];
});

it('makes a webhook address in settings that replaces the server’s, and the old one stops working', function () {
    config(['wurk.watchtower.uptime_token' => 'server-token-1234567890']);
    $this->actingAs($this->engineer)->get($this->page)->assertInertia(fn (Assert $p) => $p->where('uptime_hook.source', 'server')
        ->where('uptime_hook.url', 'https://'.config('wurk.domains.app').'/_wurk/uptime/server-token-1234567890'));

    $this->actingAs($this->engineer)->post("{$this->page}/uptime-token")->assertSessionHas('status', 'New webhook address made. Paste it into your uptime checker: the old one has stopped working.');
    $url = '';
    $this->actingAs($this->engineer)->get($this->page)->assertInertia(function (Assert $p) use (&$url) {
        $p->where('uptime_hook.source', 'settings');
        $url = (string) $p->toArray()['props']['uptime_hook']['url'];
    });
    $token = basename($url);
    expect($token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and(json_encode(DB::table('platform_settings')->get()))->not->toContain($token); // stored encrypted

    $this->postJson('/_wurk/uptime/server-token-1234567890', $this->down)->assertNotFound();
    $this->postJson("/_wurk/uptime/{$token}", $this->down)->assertNoContent();
    expect(Incident::query()->where('kind', 'downtime')->count())->toBe(1);
});

it('shows the address only to people who manage Watchtower', function () {
    config(['wurk.watchtower.uptime_token' => '']);
    $this->actingAs($this->engineer)->get($this->page)->assertInertia(fn (Assert $p) => $p->where('uptime_hook', ['url' => null, 'source' => 'none']));
    $this->postJson('/_wurk/uptime/anything-1234567890', $this->down)->assertNotFound(); // no token anywhere: no webhook
    $support = $this->staffWithRole('support', 'help@wurk.co.za');
    $this->actingAs($support)->post("{$this->page}/uptime-token")->assertForbidden();
});
