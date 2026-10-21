<?php

declare(strict_types=1);

namespace Wurk\Platform\Navigation;

use Illuminate\Contracts\Auth\Access\Gate;
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
 * Items with a permission are shown only to people who hold it (via the Gate).
 */
final readonly class NavigationBuilder
{
    public function __construct(
        private Application $app,
        private Router $router,
        private UrlGenerator $url,
        private Gate $gate,
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
                $route = $this->router->getRoutes()->getByName($entry['route']);
                if ($route === null) {
                    continue;
                }

                // Items belong to the back office they live on (admin vs business host).
                $domain = $route->getDomain();
                if ($domain !== null && strtolower($domain) !== strtolower($request->getHost())) {
                    continue;
                }

                if (isset($entry['permission']) && ! $this->gate->forUser($request->user())->allows($entry['permission'])) {
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
