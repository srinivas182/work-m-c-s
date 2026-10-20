<?php

declare(strict_types=1);

namespace Wurk\Platform\Navigation;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Wurk\Platform\Modules\ModuleServiceProvider;

/**
 * Builds the back-office menu from every module's menu() declaration.
 *
 * Items whose route does not exist are skipped, so a module can declare an item
 * for a route that is only registered in some environments or packages.
 * Permission filtering is added with authorisation in Sprint 1.
 */
final readonly class NavigationBuilder
{
    public function __construct(
        private Application $app,
        private Router $router,
        private UrlGenerator $url,
    ) {}

    /**
     * @return list<array{label: string, href: string, icon: string, section: string, active: bool}>
     */
    public function build(Request $request): array
    {
        $items = [];

        foreach ($this->app->getProviders(ModuleServiceProvider::class) as $provider) {
            if (! $provider instanceof ModuleServiceProvider) {
                continue;
            }

            foreach ($provider->menu() as $entry) {
                if (! $this->router->has($entry['route'])) {
                    continue;
                }

                $items[] = new NavigationItem(
                    label: $entry['label'],
                    href: $this->url->route($entry['route'], [], false),
                    icon: $entry['icon'] ?? 'circle',
                    section: $entry['section'] ?? 'Main',
                    order: $entry['order'] ?? 100,
                    active: $request->routeIs($entry['route'], $entry['route'].'.*'),
                );
            }
        }

        usort($items, fn (NavigationItem $a, NavigationItem $b) => [$a->order, $a->label] <=> [$b->order, $b->label]);

        return array_map(fn (NavigationItem $item) => $item->toArray(), $items);
    }
}
