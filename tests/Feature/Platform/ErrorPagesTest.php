<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

/** Sprint 18.6a: error pages in WURK's words — for full page loads and for moves around the app (Inertia visits). */
beforeEach(function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_test/fail/{code}', function (int $code) {
        if ($code === 500) {
            throw new RuntimeException('A secret internal detail');
        }
        abort($code, $code === 503 ? 'Back at 14:00 after an upgrade.' : '');
    });
    $this->inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
});

it('shows each error page in plain words, without internal details', function (int $code, string $heading) {
    $this->get("/_test/fail/{$code}")->assertStatus($code)->assertSee($heading, false)->assertSee('Error '.$code)
        ->assertDontSee('A secret internal detail')->assertDontSee('Symfony');
})->with([
    [403, 'You can’t open this page'], [404, 'We can’t find that page'], [419, 'This page timed out'],
    [429, 'That was a lot of tries in a short time'], [500, 'Something went wrong on our side'], [503, 'Back at 14:00 after an upgrade.'],
]);

it('shows an unknown address as the friendly 404', function () {
    $this->get('/this/does/not/exist')->assertNotFound()->assertSee('We can’t find that page', false);
});

it('shows errors in place when moving around the app', function () {
    foreach ([403, 404, 500] as $code) {
        $this->get("/_test/fail/{$code}", $this->inertia)->assertStatus($code)->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Error')->assertJsonPath('props.status', $code);
    }
    $this->get('/_test/fail/503', $this->inertia)->assertStatus(503)
        ->assertJsonPath('component', 'Error')->assertJsonPath('props.message', 'Back at 14:00 after an upgrade.');
    // An expired form goes back, with a message — not a dead end.
    $this->from('/somewhere')->get('/_test/fail/419', $this->inertia)->assertRedirect('/somewhere')->assertSessionHas('warning');
    // Data requests keep their JSON errors.
    $this->getJson('/_test/fail/404')->assertNotFound()->assertJsonStructure(['message']);
});
