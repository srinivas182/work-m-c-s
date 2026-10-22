<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Identity\Models\Identity;
use Wurk\Platform\Navigation\NavigationBuilder;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Props shared with every page.
     *
     * @return array<string, mixed>
     */
    /**
     * Server-side rendering only for the public marketing site (wurk.co.za), where
     * search engines need full HTML. If the SSR process is down, Inertia falls
     * back to rendering in the browser.
     */
    public function handle(Request $request, \Closure $next): Response
    {
        config(['inertia.ssr.enabled' => (bool) config('inertia.ssr.enabled')
            || (strcasecmp($request->getHost(), (string) config('wurk.domains.root')) === 0 && (bool) config('wurk.marketing_ssr', true))]);

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
            ],
            'navigation' => fn () => app(NavigationBuilder::class)->build($request),
            'auth' => fn () => [
                'user' => ($user = $request->user()) instanceof Identity ? [
                    'name' => $user->fullName(),
                    'email' => $user->email ?? $user->phone ?? '',
                ] : null,
            ],
            'flash' => fn () => ['status' => $request->session()->get('status'), 'warning' => $request->session()->get('warning')],
        ];
    }
}
