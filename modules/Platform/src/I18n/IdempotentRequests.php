<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Saved now, sent later" (Sprint 24.5, decision E): a change made offline carries its own Idempotency-Key and may be
 * sent more than once as the signal comes and goes. The first that succeeds counts; any repeat is answered "already
 * sent" without doing anything. Only requests that carry the header are affected.
 */
final class IdempotentRequests
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get(self::HEADER, '');
        if ($key === '' || $request->isMethodSafe() || preg_match('/^[A-Za-z0-9-]{8,64}$/', $key) !== 1) {
            return $next($request);
        }
        $id = 'idempotent:'.sha1(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$request->path().'|'.$key);
        if (Cache::has($id)) {
            return back(303)->with('status', __('Already sent.'));
        }
        $lock = Cache::lock($id.':lock', 30);
        if (! $lock->get()) {
            return response(__('This is being sent already.'), 409);
        }
        try {
            $response = $next($request);
            $failed = $response->getStatusCode() >= 400 // or errors flashed by this request (not a previous one's)
                || ($request->hasSession() && in_array('errors', (array) $request->session()->get('_flash.new', []), true));
            if (! $failed) {
                Cache::put($id, 1, now()->addDay());
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
