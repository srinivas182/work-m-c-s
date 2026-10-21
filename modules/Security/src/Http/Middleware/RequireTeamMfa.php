<?php

declare(strict_types=1);

namespace Wurk\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Security\Support\Settings;

/** If the business requires an authenticator, team members without one are sent to set it up first. */
final readonly class RequireTeamMfa
{
    public function __construct(private Settings $settings, private PeopleDirectory $people) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $this->settings->business('require_mfa', false) === true && ! $this->people->hasMfa((string) $user->getAuthIdentifier())) {
            return redirect()->route('identity.account')
                ->with('warning', 'This business requires an authenticator app. Set one up to continue.');
        }

        return $next($request);
    }
}
