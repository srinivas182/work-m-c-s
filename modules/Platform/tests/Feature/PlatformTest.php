<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('renders the welcome page with installed modules outside production', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Platform::Welcome')
            ->has('modules', fn (Assert $modules) => $modules->each(
                fn (Assert $module) => $module->hasAll(['name', 'version', 'description']),
            ))
            ->where('app.name', config('app.name')));
});

it('hides the module list in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('modules', []));
});

it('lists modules via artisan', function () {
    $this->artisan('wurk:modules')
        ->expectsOutputToContain('Platform')
        ->expectsOutputToContain('Health')
        ->assertSuccessful();
});
