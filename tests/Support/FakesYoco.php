<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * A simulated Yoco for tests: one set of stubs reading a changeable state (stubs added later never
 * win), a fresh checkout id per payment, and refunds matched before status reads.
 */
trait FakesYoco
{
    /** @var array{up: bool, paid: bool, amount: int|null} */
    protected array $yoco = ['up' => true, 'paid' => true, 'amount' => null];

    protected int $yocoCheckouts = 0;

    /** @var array<string, int> checkout id => amount */
    protected array $yocoAmounts = [];

    protected function fakeYoco(): void
    {
        Http::fake([
            'payments.yoco.com/api/checkouts/*/refund' => Http::response(['status' => 'succeeded']),
            'payments.yoco.com/api/checkouts/*' => function (Request $r) {
                $id = (string) basename(parse_url($r->url(), PHP_URL_PATH) ?: '');

                return Http::response(['id' => $id, 'status' => $this->yoco['paid'] ? 'completed' : 'created', 'amount' => $this->yoco['amount'] ?? ($this->yocoAmounts[$id] ?? 0)]);
            },
            'payments.yoco.com/api/checkouts' => function (Request $r) {
                if (! $this->yoco['up']) {
                    return Http::response(['message' => 'down'], 503);
                }
                $id = 'ch_'.(++$this->yocoCheckouts);
                $this->yocoAmounts[$id] = (int) $r['amount'];

                return Http::response(['id' => $id, 'redirectUrl' => "https://c.yoco.com/checkout/{$id}"]);
            },
        ]);
    }

    /** Connect Yoco (test mode) for a business. */
    protected function connectYoco(object $tenant): void
    {
        app(TenantContext::class)->run($tenant, fn () => PaymentAccount::query()->create(['provider' => 'yoco', 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_test_x'], 'webhook_secret' => 'whsec_eA==']));
    }

    protected function yocoRefunds(): int
    {
        return count(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/refund')));
    }
}
