<?php

declare(strict_types=1);

use App\Eval\ReceptionistEval;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Ai\Support\AiSettings;

uses(InteractsWithShards::class);

/**
 * Sprint 19.7: the AI receptionist's 130-conversation evaluation set (WhatsApp journeys added in 21.6), with the model's behaviour scripted. It checks
 * WURK's guardrails around any model: real bookings only, no invented times or prices, the owner's permissions,
 * and nothing reaching another customer. The same set runs against the real model by hand (wurk:eval-receptionist).
 */
it('has 130 conversations across every category in the spec', function () {
    $by = collect(ReceptionistEval::cases())->countBy('category')->all();
    expect(count(ReceptionistEval::cases()))->toBe(130)
        ->and(array_keys($by))->toEqualCanonicalizing(['booking', 'moving', 'cancelling', 'prices', 'hours', 'out_of_scope', 'abuse', 'injection', 'languages', 'no_free_time', 'closed_days', 'whatsapp'])
        ->and(collect(ReceptionistEval::cases())->pluck('id')->duplicates()->all())->toBe([])
        ->and(collect(ReceptionistEval::cases())->pluck('language')->unique()->sort()->values()->all())->toBe(['af', 'en', 'fr', 'pt', 'st', 'sw', 'xh', 'zu']);
});

it('passes every conversation with the model scripted', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-05-05 10:00', 'Africa/Johannesburg')); // a Wednesday
    $this->createShards(1);
    $ai = app(AiSettings::class); // one salon having 130 conversations in a day: above a business's normal daily cap
    $ai->save([...$ai->all(), 'caps' => [...$ai->all()['caps'], 'tenant_daily_requests' => 10000]], null);
    $eval = app(ReceptionistEval::class);
    $salon = $eval->fixture();
    $failures = [];
    foreach (ReceptionistEval::cases() as $case) {
        foreach ($eval->run($case, $salon, live: false) as $problem) {
            $failures[] = "{$case['id']}: {$problem}";
        }
    }
    expect($failures)->toBe([]);
});

it('runs against the real model by hand (here, the simulated one), scoring only what doesn’t depend on wording', function () {
    Mail::fake();
    $this->createShards(1);
    // The simulated provider, unscripted, just answers: abuse conversations pass, as nothing is booked or used.
    $this->artisan('wurk:eval-receptionist', ['--category' => 'abuse', '--json' => $file = tempnam(sys_get_temp_dir(), 'eval')])
        ->expectsOutputToContain('Passed 6 of 6 (100%).')->assertSuccessful();
    expect(json_decode((string) file_get_contents($file), true))->toMatchArray(['passed' => 6, 'total' => 6])
        ->and(app(AiSettings::class)->all()['caps']['tenant_daily_requests'])->toBe(AiSettings::DEFAULTS['caps']['tenant_daily_requests']); // the cap is put back
    @unlink($file);
    // Bookings need the model to book: unscripted, it doesn't, so the run reports it and fails.
    $this->artisan('wurk:eval-receptionist', ['--category' => 'booking'])->expectsOutputToContain('didn’t use book_appointment')->assertFailed();
    $this->artisan('wurk:eval-receptionist', ['--category' => 'nonsense'])->expectsOutput('No conversations in that category.')->assertFailed();
});
