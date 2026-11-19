<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Events\WurkWhatsAppReceived;
use Wurk\Money\Models\CashUp;
use Wurk\Money\Models\Expense;
use Wurk\Money\Models\Invoice;
use Wurk\Money\Support\Invoices;
use Wurk\Money\Support\MoneyBook;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Payments\Contracts\Refunds;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Sales\Support\SaleProblem;
use Wurk\Sales\Support\Sales;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 23.5b: the end-of-day cash-up (expected from the day's payments and sales; a difference above the owner's
 * amount needs a reason; locked, and only an owner reopens it), expenses typed or read from a slip, profit in plain
 * words, and Monday's "how did I do" on WhatsApp (STOP turns it off).
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('local');
    SimulatedDriver::$nextAnswer = null;
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person();
    $this->owner->forceFill(['phone_verified_at' => now()])->save();
    $this->member($this->owner, $this->glow);
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'crm', 'ai-assistant'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Zodwa', 'owner');
        app(CreditWallet::class)->grant('purchase', 100000, 'buy:1', 'Purchase');
    });
    $this->base = $this->app('/glow-salon/money');
    $this->today = now('Africa/Johannesburg')->toDateString();
});

it('expects the day’s cash and card payments, asks why R50 is missing, and locks the day until an owner reopens it', function () {
    ($this->in)(function () {
        app(InPersonPayments::class)->record('order', '01J000000000000000000ORDER', 'cash', 30000, $this->owner->id, 'Zodwa', null);
        app(InPersonPayments::class)->record('order', '01J000000000000000000ORDE2', 'card_machine', 12000, $this->owner->id, 'Zodwa', null);
        app(Sales::class)->record(['name' => 'Sipho', 'phone' => '0825550103', 'email' => null, 'amount_cents' => 20000, 'method' => 'cash', 'note' => null, 'branch_id' => null], $this->owner->id, 'Zodwa', null, true);
        app(Sales::class)->record(['name' => 'Lerato', 'phone' => '0825550104', 'email' => null, 'amount_cents' => 9000, 'method' => 'account', 'note' => null, 'branch_id' => null], $this->owner->id, 'Zodwa', null, true);
    });
    $this->actingAs($this->owner)->get($this->base.'/cash-up')->assertInertia(fn (Assert $p) => $p->component('Money::CashUp')
        ->where('expected', ['cash' => 50000, 'card_machine' => 12000, 'qr' => 0])->where('cashUp', null)->where('isOwner', true));

    $this->actingAs($this->owner)->post($this->base.'/cash-up', ['day' => $this->today, 'cash' => 450, 'card_machine' => 120])
        ->assertSessionHasErrors(['reason' => 'The cash is R50.00 short. Say why before locking the day.']);
    $this->actingAs($this->owner)->post($this->base.'/cash-up', ['day' => $this->today, 'cash' => 450, 'card_machine' => 120, 'reason' => 'Paid the window cleaner from the till'])
        ->assertSessionHas('status', 'Cashed up and locked: R50.00 short.');
    $this->actingAs($this->owner)->post($this->base.'/cash-up', ['day' => $this->today, 'cash' => 500])->assertSessionHasErrors('day');

    $cashier = $this->person(['email' => 'thabo@glow.co.za', 'phone' => '+27821234568', 'first_name' => 'Thabo']);
    $this->member($cashier, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($cashier->id, 'Thabo', 'staff'));
    $id = ($this->in)(fn () => CashUp::query()->value('id'));
    expect($this->actingAs($cashier)->post($this->base.'/cash-up/'.$id.'/reopen')->status())->toBeIn([403, 404]);
    $this->actingAs($this->owner)->post($this->base.'/cash-up/'.$id.'/reopen')->assertSessionHas('status');
    $this->actingAs($this->owner)->post($this->base.'/cash-up', ['day' => $this->today, 'cash' => 500, 'card_machine' => 120])->assertSessionHas('status', 'Cashed up and locked: the cash matches.');
});

it('adds expenses typed or read from a slip (confirmed first), and says what the month made', function () {
    ($this->in)(fn () => app(Sales::class)->record(['name' => 'Sipho', 'phone' => '0825550103', 'email' => null, 'amount_cents' => 120000, 'method' => 'cash', 'note' => null, 'branch_id' => null], $this->owner->id, 'Zodwa', null, true));
    $this->actingAs($this->owner)->post($this->base.'/expenses', ['amount' => 350, 'category' => 'rent', 'spent_on' => $this->today, 'note' => 'Chair rent'])->assertSessionHas('status', 'R350.00 added to rent.');

    SimulatedDriver::$nextAnswer = '{"amount": 189.5, "date": "'.$this->today.'", "category": "stock", "note": "Makro: braiding hair"}';
    $img = imagecreatetruecolor(400, 600);
    ob_start();
    imagejpeg($img);
    $slip = UploadedFile::fake()->createWithContent('slip.jpg', (string) ob_get_clean());
    $this->actingAs($this->owner)->post($this->base.'/expenses/slip', ['slip' => $slip])->assertSessionHas('slip', fn ($s) => $s['amount'] === '189.50' && $s['category'] === 'stock' && $s['note'] === 'Makro: braiding hair');
    expect(($this->in)(fn () => Expense::query()->count()))->toBe(1); // nothing added until confirmed
    $slipId = (string) session('slip')['id'];
    $this->actingAs($this->owner)->post($this->base.'/expenses', ['amount' => 189.5, 'category' => 'stock', 'spent_on' => $this->today, 'note' => 'Makro: braiding hair', 'slip_id' => $slipId])->assertSessionHasNoErrors();
    $e = ($this->in)(fn () => Expense::query()->where('from_slip', true)->sole());
    $this->actingAs($this->owner)->get($this->base.'/expenses/'.$e->id.'/slip')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $this->actingAs($this->owner)->get($this->base.'/expenses')->assertInertia(fn (Assert $p) => $p->component('Money::Expenses')
        ->where('profit.took', 120000)->where('profit.spent', 35000 + 18950)->where('profit.made', 120000 - 53950));
});

it('sends Monday’s summary to the owner on WhatsApp, once a week, until they reply STOP', function () {
    $messenger = new class implements Messenger
    {
        /** @var list<array{0: string, 1: string, 2: list<string>}> */
        public array $sent = [];

        public function sms(string $to, string $text, string $purpose): SendResult
        {
            return SendResult::failed('no');
        }

        public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
        {
            $this->sent[] = [$to, $template, $parameters];

            return SendResult::sent('w1');
        }

        public function channelActive(string $channel): bool
        {
            return true;
        }
    };
    $this->app->instance(Messenger::class, $messenger);
    $this->travelTo(now('Africa/Johannesburg')->next('Monday')->setTime(8, 20));
    $this->artisan('money:weekly-summaries')->assertSuccessful();
    $this->artisan('money:weekly-summaries')->assertSuccessful(); // once a week
    expect($messenger->sent)->toHaveCount(1)->and($messenger->sent[0][0])->toBe('+27821234567')->and($messenger->sent[0][1])->toBe('wurk_weekly_summary')
        ->and($messenger->sent[0][2][0])->toBe('Zodwa')->and($messenger->sent[0][2][1])->toBe('Glow Salon')->and($messenger->sent[0][2][2])->toStartWith('You took R0.00')
        ->and($messenger->sent[0][2][4])->toBe('Nobody owes you money.');

    event(new WurkWhatsAppReceived('27821234567', 'Stop'));
    $this->travel(7)->days();
    $this->artisan('money:weekly-summaries')->assertSuccessful();
    expect($messenger->sent)->toHaveCount(1);
    $this->actingAs($this->owner)->put($this->base.'/settings', ['weekly_summary' => true])->assertSessionHas('status', 'Saved.'); // switching it on undoes STOP
    $this->travel(7)->days();
    $this->artisan('money:weekly-summaries')->assertSuccessful();
    expect($messenger->sent)->toHaveCount(2);
});

it('records a change sent twice with the same key once (saved offline, sent later)', function () {
    $send = fn (array $data) => $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'k-1234567890-abc')->post($this->base.'/expenses', $data);
    $send(['amount' => 0, 'category' => 'rent', 'spent_on' => $this->today])->assertSessionHasErrors('amount'); // a problem: not remembered
    $send(['amount' => 80, 'category' => 'transport', 'spent_on' => $this->today])->assertSessionHas('status', 'R80.00 added to transport.');
    $send(['amount' => 80, 'category' => 'transport', 'spent_on' => $this->today])->assertSessionHas('status', 'Already sent.');
    expect(($this->in)(fn () => Expense::query()->count()))->toBe(1);
    $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'k-0987654321-xyz')->post($this->base.'/expenses', ['amount' => 80, 'category' => 'transport', 'spent_on' => $this->today]);
    expect(($this->in)(fn () => Expense::query()->count()))->toBe(2); // a different change
});

it('counts a refund paid back at the counter as money out on the day it’s paid, with its reason, and tells the customer (25.5)', function () {
    $sale = ($this->in)(fn () => app(Sales::class)->record(['name' => 'Sipho', 'phone' => '0825550103', 'email' => 'sipho@example.com', 'amount_cents' => 20000, 'method' => 'cash',
        'note' => null, 'branch_id' => null], $this->owner->id, 'Zodwa', null, true));
    $this->travel(1)->days();
    $today = now('Africa/Johannesburg')->toDateString();
    ($this->in)(fn () => app(Sales::class)->refund($sale, 5000, 'Zodwa', 'cash', 'Wrong size', 'Sorry about that!', $this->owner->id));

    $this->actingAs($this->owner)->get($this->base.'/cash-up?day='.$today)->assertInertia(fn (Assert $p) => $p->where('expected.cash', -5000)); // R50 out today
    $this->actingAs($this->owner)->get($this->base.'/cash-up?day='.$this->today)->assertInertia(fn (Assert $p) => $p->where('expected.cash', 20000)); // the sale's day keeps it in full
    $refund = ($this->in)(fn () => app(Refunds::class)->for('sale', $sale->id));
    expect($refund)->toHaveCount(1)->and($refund[0])->toMatchArray(['amount_cents' => 5000, 'method' => 'cash', 'reason' => 'Wrong size', 'note' => 'Sorry about that!', 'by_name' => 'Zodwa', 'told_by' => 'email']);

    expect(fn () => ($this->in)(fn () => app(Sales::class)->refund($sale, 1000, 'Zodwa', 'eft', 'x')))->toThrow(SaleProblem::class, 'can’t be refunded that way');
});

it('refunds a paid invoice: online back through the provider, in person by hand; a reason is needed (25.5)', function () {
    $invoice = ($this->in)(function () {
        $i = app(Invoices::class)->create(['customer_name' => 'Thandi Mokoena', 'customer_email' => 'thandi@example.com', 'lines' => [['description' => 'Wedding hair', 'quantity' => 1, 'unit' => 1200]],
            'due_on' => now()->addDays(7)->toDateString()], null);
        app(Invoices::class)->markPaid($i, 'cash', $this->owner->id, 'Zodwa');

        return $i->refresh();
    });
    $url = $this->base.'/invoices/'.$invoice->id.'/refund';
    $this->actingAs($this->owner)->post($url, ['amount' => '200', 'method' => 'cash'])->assertSessionHasErrors(['reason' => 'Say why — it’s kept with the refund.']);
    $this->actingAs($this->owner)->post($url, ['amount' => '200', 'method' => 'provider', 'reason' => 'Late'])->assertSessionHasErrors('method');
    $this->actingAs($this->owner)->post($url, ['amount' => '2000', 'method' => 'cash', 'reason' => 'Late'])->assertSessionHasErrors('amount');
    $this->actingAs($this->owner)->post($url, ['amount' => '200', 'method' => 'cash', 'reason' => 'Late start'])->assertSessionHas('status', 'INV-0001 refunded — the customer has been told.');

    expect(($this->in)(fn () => Invoice::query()->find($invoice->id)->refunded_cents))->toBe(20000)
        ->and(($this->in)(fn () => app(MoneyBook::class)->profit(now()->startOfMonth(), now()->endOfMonth())['took']))->toBe(120000 - 20000)
        ->and(($this->in)(fn () => app(MoneyBook::class)->expected($this->today)['cash']))->toBe(120000 - 20000);

    // The customer's invoice shows the refund, with the VAT inside it reversed in proportion.
    $link = ($this->in)(fn () => app(Invoices::class)->link(Invoice::query()->findOrFail($invoice->id)));
    $vat = ($this->in)(fn () => Invoices::refundVat(Invoice::query()->findOrFail($invoice->id), 20000));
    $this->get($link)->assertOk()->assertSee('Refunded')->assertSee('−R200.00', false);
    expect($vat)->toBe(intdiv($invoice->vat_cents * 20000 * 2 + $invoice->total_cents, $invoice->total_cents * 2));
});
