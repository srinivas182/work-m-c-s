<?php

declare(strict_types=1);

use Wurk\Ai\Models\AiRequest;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Contracts\CreditCostEstimates;

it('works out what one credit costs WURK, per channel', function () {
    app(ProviderSettings::class)->save('sms', 'smsportal', ['client_id' => 'id', 'api_secret' => 'secret'], ['cost_zar' => '0.25'], true, null);
    $costs = app(CreditCostEstimates::class)->perCredit();

    expect(round($costs['sms']['zar_per_credit'], 4))->toBe(0.0833)       // R0.25 per SMS ÷ 3 credits
        ->and($costs['email']['zar_per_credit'])->toBeNull()                // cost not set yet
        ->and(round($costs['ai.generate_site']['zar_per_credit'], 5))->toBe(0.06804); // $0.072 × R18 × 1.05 ÷ 20 credits

    // Real usage replaces the estimate: average $0.10 per website.
    AiRequest::query()->create(['task' => 'site_generation', 'provider' => 'anthropic', 'model' => 'm', 'cost_usd_micro' => 100000, 'status' => 'succeeded', 'created_at' => now()]);
    expect(round(app(CreditCostEstimates::class)->perCredit()['ai.generate_site']['zar_per_credit'], 4))->toBe(0.0945); // $0.10 × 18.9 ÷ 20
});
