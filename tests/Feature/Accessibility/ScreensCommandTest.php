<?php

declare(strict_types=1);

use App\Console\Commands\ScreensCommand;
use Illuminate\Support\Facades\Route;

/** Sprint 18.5a: the accessibility crawl's list of screens (the crawl itself runs in e2e/a11y-screens.spec.ts). */
it('reviews only routes that exist, and only GET routes, as not being screens', function () {
    foreach (array_keys(ScreensCommand::NOT_SCREENS) as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("{$name} is listed as not a screen but no longer exists — remove it")
            ->and($route?->methods())->toContain('GET');
    }
});

it('never runs in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('wurk:screens')->expectsOutputToContain('Never in production')->assertFailed();
});
