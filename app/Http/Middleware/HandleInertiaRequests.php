<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Support\Surface;
use Wurk\Platform\I18n\Lite;
use Wurk\Platform\I18n\Locales;
use Wurk\Platform\Money\Money;
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
            // No back-office menu on a business's customer portal (the person may also be staff somewhere).
            'navigation' => fn () => Surface::of($request) === Surface::PORTAL ? [] : app(NavigationBuilder::class)->build($request),
            'auth' => fn () => [
                'user' => ($user = $request->user()) instanceof Identity ? [
                    'name' => $user->fullName(),
                    'email' => $user->email ?? $user->phone ?? '',
                ] : null,
            ],
            // The language (24.1): its translations load as a script before the app (resources/views/app.blade.php).
            'i18n' => fn () => ['locale' => app()->getLocale(), 'languages' => Locales::offered()],
            'currency' => fn () => Money::business()->toArray(), // 25.1b: how the business's money is written
            'lite' => fn () => ['on' => Lite::on($request), 'choice' => in_array($request->cookie(Lite::COOKIE), ['on', 'off'], true) ? $request->cookie(Lite::COOKIE) : 'auto'], // 24.4
            'flash' => fn () => ['status' => self::translated($request->session()->get('status')), 'warning' => self::translated($request->session()->get('warning'))],
            // A page-wide notice a module attached to this request (e.g. Billing's payment reminder).
            'banner' => fn () => $request->attributes->get('wurk.banner'),
            // "Ask WURK" in a business's back office (Sprint 16.2), when on.
            'assistant' => fn () => $request->attributes->get('wurk.assistant'),
            // "Contact support" in a business's back office (Sprint 16.3).
            'support' => fn () => $request->attributes->get('wurk.support'),
        ];
    }

    /** A fixed flash message in the person's language (24.1); messages built with values stay as they are. */
    private static function translated(mixed $message): mixed
    {
        return is_string($message) ? __($message) : $message;
    }
}
