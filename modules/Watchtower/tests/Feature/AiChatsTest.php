<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Wurk\Ai\Models\AiRequest;
use Wurk\Watchtower\Models\Incident;

/** Sprint 19.7: Watchtower watches the AI receptionist's and the sales assistant's replies, an hour at a time. */
beforeEach(function () {
    Mail::fake();
    config(['wurk.watchtower.recipients' => ['hq@example.test']]);
    $this->travelTo(CarbonImmutable::parse('2027-05-05 10:20'));
    $this->step = fn (string $task, string $status, int $ms, string $at = '2027-05-05 09:30') => AiRequest::query()->create([
        'task' => $task, 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'status' => $status, 'duration_ms' => $ms, 'created_at' => CarbonImmutable::parse($at),
    ]);
});

it('raises a problem when many AI replies fail or are very slow in the hour that ended, once', function () {
    foreach (range(1, 12) as $i) {
        ($this->step)('receptionist', $i <= 4 ? 'failed' : 'succeeded', 3000);
    }
    foreach (range(1, 6) as $i) {
        ($this->step)('sales', 'succeeded', 20_000);
    }
    ($this->step)('receptionist', 'failed', 100, '2027-05-05 10:05'); // this hour isn't over: not yet

    $this->artisan('watchtower:check')->assertSuccessful();
    $this->artisan('watchtower:check')->assertSuccessful(); // the same hour isn't counted twice
    $failing = Incident::query()->where('title', 'Replies failing: the AI receptionist')->sole();
    expect($failing)->kind->toBe('check')->severity->toBe('high')->occurrences->toBe(1)
        ->and($failing->message)->toContain('4 of 12 AI steps failed')
        ->and(Incident::query()->where('title', 'Slow replies: WURK’s sales assistant')->value('kind'))->toBe('slow')
        ->and(Incident::query()->where('title', 'like', '%sales assistant%')->where('kind', 'check')->exists())->toBeFalse();
});

it('stays quiet for a normal hour, and for too few replies to judge', function () {
    foreach (range(1, 30) as $i) {
        ($this->step)('receptionist', $i === 1 ? 'failed' : 'succeeded', 4000);
    }
    foreach (range(1, 3) as $i) {
        ($this->step)('sales', 'failed', 100); // 3 of 3, but too few to judge
    }
    $this->artisan('watchtower:check')->assertSuccessful();
    expect(Incident::query()->where('location', 'like', 'ai:%')->count())->toBe(0);
});
