<?php

declare(strict_types=1);

namespace Wurk\Security\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /.well-known/security.txt (RFC 9116, Sprint 18.1b) on each of WURK's own addresses: who to tell about a security
 * problem, and the disclosure policy. Expires is always about six months ahead, so the file never goes stale.
 */
final class SecurityTxtController
{
    public function __invoke(Request $request): Response
    {
        $root = 'https://'.config('wurk.domains.root');
        $lines = [
            'Contact: mailto:'.config('wurk.security.contact'),
            "Contact: {$root}/security",
            'Expires: '.now()->addDays(180)->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: en',
            "Policy: {$root}/security",
            'Canonical: https://'.$request->getHost().'/.well-known/security.txt',
        ];

        return new Response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=86400']);
    }
}
