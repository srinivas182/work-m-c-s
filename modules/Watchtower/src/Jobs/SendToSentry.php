<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Platform\Support\SentryDsn;

/** Sends one already-masked error to Sentry (17.5). Best effort: Watchtower already has it. */
final class SendToSentry implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    /** @param array<string, mixed> $event */
    public function __construct(public readonly array $event) {}

    public function handle(ProviderCredentials $providers): void
    {
        $dsn = SentryDsn::parse((string) ($providers->active('errors.sentry')['credentials']['dsn'] ?? ''));
        if ($dsn === null) {
            return;
        }
        try {
            Http::timeout(10)->withHeaders($dsn->headers())->withBody(SentryDsn::envelope($this->event), 'application/x-sentry-envelope')->post($dsn->endpoint);
        } catch (Throwable) {
            // never let reporting an error cause another
        }
    }
}
