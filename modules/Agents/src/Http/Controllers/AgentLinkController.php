<?php

declare(strict_types=1);

namespace Wurk\Agents\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Wurk\Agents\Support\AgentAttribution;

/** wurk.co.za/a/CODE: remember the agent (first link wins), then the marketing site. */
final class AgentLinkController
{
    public function __invoke(Request $request, string $code, AgentAttribution $attribution): RedirectResponse
    {
        $agent = $attribution->activeByCode($code);
        $response = redirect('/');
        if ($agent !== null && ! is_string($request->cookie(AgentAttribution::COOKIE))) {
            $response = $response->withCookie($attribution->cookie($agent, $request));
        }

        return $response;
    }
}
