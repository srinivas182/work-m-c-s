<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Listeners\ForgetTenantContext;
use Wurk\Tenancy\Models\Branch;

uses(InteractsWithShards::class);

final class RecordTenantJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<array{tenant: string, branches: int}> */
    public static array $seen = [];

    public function handle(TenantContext $context): void
    {
        self::$seen[] = ['tenant' => $context->current()->handle(), 'branches' => Branch::query()->count()];
    }
}

it('runs a queued job in the tenant it was dispatched from', function () {
    config(['queue.default' => 'database']);
    $this->createShards(2);
    $a = $this->createTenant('tenant-a');
    $b = $this->createTenant('tenant-b');
    $context = app(TenantContext::class);
    RecordTenantJob::$seen = [];

    // Note: dispatch() returns a PendingDispatch that dispatches when destroyed, so it
    // must not be the return value of run() (it would dispatch after the context ends).
    $context->run($a, function (): void {
        dispatch(new RecordTenantJob);
    });
    $context->enter($b); // the worker's current state must not matter
    $context->forget();

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect(RecordTenantJob::$seen)->toBe([['tenant' => 'tenant-a', 'branches' => 1]])
        ->and($context->has())->toBeFalse();
});

it('clears the tenant at Octane request boundaries', function () {
    expect(Event::hasListeners(RequestReceived::class))->toBeTrue()
        ->and(Event::hasListeners(RequestTerminated::class))->toBeTrue();

    $this->createShards(1);
    $tenant = $this->createTenant('tenant-a');
    $context = app(TenantContext::class);
    $context->enter($tenant);

    app(ForgetTenantContext::class)->handle(new stdClass);

    expect($context->has())->toBeFalse();
});
