<?php

declare(strict_types=1);

namespace Wurk\Push\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wurk\Push\Models\PushSubscription;
use Wurk\Push\Support\WebPush;

/** One notification to one phone. A phone the push service no longer knows (404/410) is forgotten. */
final class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public string $subscriptionId, public string $payload) {}

    public function handle(WebPush $push): void
    {
        $s = PushSubscription::query()->find($this->subscriptionId);
        if ($s === null) {
            return;
        }
        $status = $push->send($s->endpoint, $s->p256dh, $s->auth, $this->payload);
        if (in_array($status, [404, 410], true)) {
            $s->delete();
        } elseif ($status >= 200 && $status < 300) {
            $s->forceFill(['last_sent_at' => now()])->save();
        } elseif ($status === 0 || $status === 429 || $status >= 500) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
        }
    }
}
