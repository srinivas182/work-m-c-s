<?php

declare(strict_types=1);

/*
 * Browser tests for Sprint 25 (25.7), run by scripts/e2e-setup through `php artisan tinker`:
 * - USSD: WURK's Africa's Talking settings with a test webhook token (the tests post to the webhook as Africa's
 *   Talking would; nothing is sent to it), and credits for the demo salon's sessions.
 * - Reviews: a finished visit's review request at the demo salon, with its signed link written for the test.
 */

use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Reviews\Models\ReviewRequest;
use Wurk\Reviews\Support\Reviews;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

app(ProviderSettings::class)->save('ussd', 'africastalking', ['username' => 'sandbox', 'api_key' => 'e2e-not-a-key'],
    ['service_code' => '*384*1234#', 'webhook_token' => 'e2e-ussd-token-0001'], true, null);

$salon = app(TenantDirectory::class)->findByHandle('demo-salon-hair-braids');
$link = app(TenantContext::class)->run($salon, function () {
    app(CreditWallet::class)->grant('purchase', 100000, 'e2e:sprint-25', 'Test credits');
    $request = ReviewRequest::query()->create(['visit_key' => 'booking:e2e-review', 'kind' => 'booking', 'title' => 'Knotless braids',
        'customer_name' => 'Thandi Mokoena', 'phone' => '+27825550190', 'channel' => 'sms', 'sent_at' => now()]);

    return app(Reviews::class)->link($request);
});
file_put_contents(storage_path('app/e2e-sprint25.json'), json_encode(['review' => $link], JSON_UNESCAPED_SLASHES));
