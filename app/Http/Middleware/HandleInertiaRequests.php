<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
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
