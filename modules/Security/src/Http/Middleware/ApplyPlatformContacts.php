<?php

declare(strict_types=1);

namespace Wurk\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Security\Support\PlatformContacts;

/** WURK's saved contact addresses for this request (19.6; Octane workers live across many requests). */
final readonly class ApplyPlatformContacts
{
    public function __construct(private PlatformContacts $contacts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->contacts->apply();

        return $next($request);
    }
}
