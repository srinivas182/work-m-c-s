<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Wurk\Platform\Navigation\NavigationBuilder;

it('serves the design showcase outside production', function () {
    $this->get('/_design')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Platform::DesignSystem')
            ->where('navigation', fn ($nav) => collect($nav)->contains(
                fn ($item) => $item['label'] === 'Design system' && $item['href'] === '/_design' && $item['active'] === true,
            )));
});

it('does not register the showcase route when disabled', function () {
    // Routes are registered at boot, so simulate a production boot.
    config(['wurk.design_showcase' => false]);
    Route::setRoutes(new RouteCollection);
    require base_path('modules/Platform/routes/web.php');
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('platform.design'))->toBeFalse()
        ->and(Route::has('home'))->toBeTrue();
});

it('skips navigation items whose route is not registered', function () {
    config(['wurk.design_showcase' => false]);
    Route::setRoutes(new RouteCollection);
    require base_path('modules/Platform/routes/web.php');
    Route::getRoutes()->refreshNameLookups();

    $nav = app(NavigationBuilder::class)->build(request());

    expect(collect($nav)->pluck('label'))->not->toContain('Design system');
});
