<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wurk\Watchtower\Support\Mask;
use Wurk\Watchtower\Support\Recorder;
use Wurk\Watchtower\Support\Signal;
use Wurk\Watchtower\Support\Where;

/**
 * POST /_wurk/client-error (Sprint 18.0): a crash in someone's browser. Grouped by message and the line in WURK's
 * script that threw (build hashes taken out, so the same bug in a new build is the same problem). Rate-limited;
 * stateless; masked like every other report.
 */
final class ClientErrorController
{
    public function __invoke(Request $request, Recorder $recorder): Response
    {
        $d = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'page' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', 'in:error,rejection,render'],
        ]);
        $stack = Mask::text(self::stripBuild((string) ($d['stack'] ?? '')), 3000);
        $frame = '';
        foreach (explode("\n", $stack) as $l) {
            if (preg_match('#(/build/assets/[^\s):]+:\d+)#', $l, $m) === 1) {
                $frame = $m[1];
                break;
            }
        }
        $page = preg_replace('/[^A-Za-z0-9_:\/.\-]/', '', (string) ($d['page'] ?? '')) ?: null;
        $message = Mask::text($d['message'], 500);
        $recorder->record(new Signal(
            kind: 'frontend',
            group: Mask::normalise($d['message']).'|'.$frame.'|'.$page,
            title: mb_substr('Browser: '.$message, 0, 250),
            message: $message,
            file: $frame !== '' ? $frame : null,
            location: $page !== null ? "page {$page}" : null,
            surface: Where::surface($request),
            trace: $stack !== '' ? $stack : null,
            context: ['kind' => $d['kind'] ?? 'error', 'browser' => self::browser((string) $request->userAgent())],
        ));

        return response()->noContent();
    }

    /** "app-3f9a1c.js" → "app.js": the same line in a new build is the same bug. Query strings and hosts removed. */
    public static function stripBuild(string $stack): string
    {
        $stack = (string) preg_replace('#https?://[^/\s]+#', '', $stack);
        $stack = (string) preg_replace('#\?[^\s:)]*#', '', $stack);

        return (string) preg_replace('#([A-Za-z0-9_]+)-[A-Za-z0-9_]{8}\.(js|css)#', '$1.$2', $stack);
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Other',
        }.(preg_match('/Mobile|Android|iPhone/', $ua) === 1 ? ' (mobile)' : '');
    }
}
