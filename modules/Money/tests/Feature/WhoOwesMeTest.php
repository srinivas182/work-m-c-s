<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Events\WhatsAppReceived;
use Wurk\Money\Mail\InvoiceMail;
use Wurk\Money\Models\Invoice;
use Wurk\Money\Support\Invoices;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\I18n\TranslationStore;
use Wurk\Regions\Models\TaxRate;
use Wurk\Sales\Models\Sale;
use Wurk\Sales\Support\Sales;
use Wurk\Shop\Models\Order;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 23.5a: who owes me — one list across modules — and simple invoices: VAT, a signed pay link, paid online
 * (confirmed with the provider) or in person, and reminders on days 1, 7 and 14 (one a week at most, STOP respected).
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop', 'crm'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Zodwa', 'owner');
        app(CreditWallet::class)->grant('purchase', 100000, 'buy:1', 'Purchase');
    });
    $this->messenger = new class implements Messenger
    {
        /** @var list<array{0: string, 1: string, 2: string}> */
        public array $sms = [];

        public function sms(string $to, string $text, string $purpose): SendResult
        {
            $this->sms[] = [$to, $text, $purpose];

            return SendResult::sent('s1');
        }

        public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
        {
            return SendResult::failed('none');
        }

        public function channelActive(string $channel): bool
        {
            return $channel === 'sms';
        }
    };
    $this->app->instance(Messenger::class, $this->messenger);
    $this->payments = new class implements BusinessPayments
    {
        public bool $paid = false;

        public function available(?string $branchId = null): array
        {
            return ['paystack'];
        }

        public function start(string $reference, int $amountCents, string $description, array $customer, array $urls, ?string $provider = null, ?string $branchId = null): ?string
        {
            return 'https://checkout.paystack.test/'.$reference;
        }

        public function verify(string $reference): array
        {
            return ['paid' => $this->paid, 'amount_cents' => 138000];
        }

        public function refund(string $reference, ?int $amountCents = null): bool
        {
            return false;
        }

        public function promptMobileMoney(string $reference, string $phone): ?string
        {
            return null;
        }

        public function eftInstructions(?string $branchId = null): ?string
        {
            return "FNB · Glow Salon\nAccount 62000000000";
        }

        public function setUpEft(string $instructions): void {}

        public function receivedBy(string $reference): ?string
        {
            return null;
        }

        public function accountBranch(string $provider, ?string $branchId): ?string
        {
            return null;
        }
    };
    $this->app->instance(BusinessPayments::class, $this->payments);
    $this->base = $this->app('/glow-salon/money');
    $this->invoice = fn (array $extra = []) => $this->actingAs($this->owner)->post($this->base.'/invoices', [
        'customer_name' => 'Thandi Mokoena', 'lines' => [['description' => 'Wedding hair', 'quantity' => 3, 'unit' => 400]], 'due_on' => now()->addDays(7)->toDateString(), 'vat' => true, 'vat_number' => '4123456789', ...$extra,
    ]);
});

it('makes an invoice with VAT, sends it the best way it can, and is paid by its signed link', function () {
    ($this->invoice)(['lines' => [['description' => 'Wedding hair', 'quantity' => 1, 'unit' => 0]]])->assertSessionHasErrors(['lines.0.unit' => 'Each line needs a price.']);
    ($this->invoice)(['send' => true])->assertSessionHas('status', 'INV-0001 is ready: there’s no number or email to send it to, so share its link.');
    $i = ($this->in)(fn () => Invoice::query()->sole());
    expect([$i->subtotal_cents, $i->vat_cents, $i->total_cents, $i->status, $i->sent_by])->toBe([120000, 18000, 138000, 'sent', 'link']);

    ($this->invoice)(['customer_email' => 'thandi@example.com', 'send' => true])->assertSessionHas('status', 'INV-0002 sent by email.');
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->number === 'INV-0002' && $m->amount === 'R1 380.00' && ! $m->reminder);
    ($this->invoice)(['customer_phone' => '082 555 0101', 'send' => true])->assertSessionHas('status', 'INV-0003 sent by SMS.');
    expect($this->messenger->sms[0][0])->toBe('+27825550101')->and($this->messenger->sms[0][1])->toStartWith('Hi Thandi, Glow Salon has sent you a request to pay R1 380.00 for INV-0003. You can view and pay it here: http://glow-salon.');

    // The customer's page: signed links only; online payment confirmed with the provider before it counts.
    $link = ($this->in)(fn () => app(Invoices::class)->link($i));
    $this->get(explode('?', $link)[0])->assertForbidden();
    $this->get($link)->assertOk()->assertSee('Invoice INV-0001')->assertSee('Wedding hair')->assertSee('VAT number 4123456789')->assertSee('R1 380.00')->assertSee('Account 62000000000');
    $pay = ($this->in)(fn () => URL::signedRoute('money.public.pay', ['handle' => 'glow-salon', 'invoice' => $i->id]));
    $this->post($pay)->assertRedirect('https://checkout.paystack.test/inv_'.$this->glow->id.'_'.$i->id);
    ($this->in)(fn () => event(new BusinessPaymentReceived($this->glow->id, 'inv_'.$this->glow->id.'_'.$i->id))); // not paid yet: nothing changes
    ($this->in)(fn () => expect(Invoice::query()->find($i->id)->status)->toBe('sent'));
    $this->payments->paid = true;
    ($this->in)(fn () => event(new BusinessPaymentReceived($this->glow->id, 'inv_'.$this->glow->id.'_'.$i->id)));
    ($this->in)(fn () => expect(Invoice::query()->find($i->id)->only(['status', 'paid_how']))->toBe(['status' => 'paid', 'paid_how' => 'online']));
    $this->get($link)->assertSee('Paid');
});

it('reminds on days 1, 7 and 14 after the due date — never twice in a week — and stops when the customer says STOP', function () {
    ($this->invoice)(['customer_phone' => '082 555 0101', 'customer_email' => 'thandi@example.com', 'send' => true, 'due_on' => now()->toDateString()]);
    $sms = fn () => count($this->messenger->sms);
    $run = fn () => $this->artisan('money:remind-invoices')->assertSuccessful();
    expect($sms())->toBe(1); // sent
    $run();
    expect($sms())->toBe(1); // not overdue yet
    $this->travel(1)->days();
    $run();
    $run();
    expect($sms())->toBe(2)->and($this->messenger->sms[1][1])->toContain('due '.now()->subDay()->format('j M'))->toEndWith('Reply STOP to stop these reminders.');
    $this->travel(3)->days();
    $this->actingAs($this->owner)->post($this->base.'/invoices/'.($this->in)(fn () => Invoice::query()->value('id')).'/remind')->assertSessionHas('warning'); // one a week at most
    $this->travel(3)->days(); // day 7
    $run();
    expect($sms())->toBe(3);
    ($this->in)(fn () => event(new WhatsAppReceived($this->glow->id, '27825550101', 'Thandi', 'wamid.1', 'text', ' stop ', null, null, null, new DateTimeImmutable)));
    $this->travel(7)->days(); // day 14: by email now, not SMS
    $run();
    expect($sms())->toBe(3);
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->reminder);
    $this->travel(7)->days();
    $run();
    expect(($this->in)(fn () => Invoice::query()->value('reminders_sent')))->toBe(3); // three in all
});

it('lists everything owed — invoices, pay-later orders, sales on account — and marks them paid in person', function () {
    ($this->invoice)(['send' => true, 'due_on' => now()->addDays(3)->toDateString()]);
    ($this->in)(function () {
        Order::query()->create(['number' => 1001, 'status' => 'awaiting_payment', 'payment_method' => 'offline', 'fulfilment' => 'collection', 'customer_name' => 'Lerato',
            'customer_phone' => '0825550102', 'subtotal_cents' => 25000, 'total_cents' => 25000]);
        app(Sales::class)->record(['name' => 'Sipho', 'phone' => '0825550103', 'email' => null, 'amount_cents' => 9000, 'method' => 'account', 'note' => null, 'branch_id' => null], $this->owner->id, 'Zodwa', null, true);
    });
    $this->actingAs($this->owner)->get($this->base.'/owed')->assertInertia(fn (Assert $p) => $p->component('Money::Owed')->where('total_cents', 138000 + 25000 + 9000)
        ->where('owed', fn ($o) => collect($o)->pluck('kind')->sort()->values()->all() === ['invoice', 'order', 'sale']));

    $sale = ($this->in)(fn () => Sale::query()->sole());
    $this->actingAs($this->owner)->post($this->app('/glow-salon/sales/'.$sale->id.'/settle'), ['how' => 'cash', 'to' => 'paid'])->assertSessionHas('status', 'Marked paid.');
    $invoice = ($this->in)(fn () => Invoice::query()->sole());
    $this->actingAs($this->owner)->post($this->base.'/invoices/'.$invoice->id.'/paid', ['how' => 'card_machine', 'to' => 'paid'])->assertSessionHas('status', 'INV-0001 marked paid.');
    ($this->in)(fn () => expect(app(InPersonPayments::class)->takenFor('sale', $sale->id))->toBe(9000)
        ->and(app(InPersonPayments::class)->takenFor('invoice', $invoice->id))->toBe(138000));
    $this->actingAs($this->owner)->get($this->base.'/owed')->assertInertia(fn (Assert $p) => $p->where('total_cents', 25000)->where('owed.0.kind', 'order'));
    $this->actingAs($this->owner)->post($this->base.'/invoices/'.$invoice->id.'/void')->assertSessionHasErrors('invoice');
});

it('is for people who manage the money', function () {
    $staff = $this->person(['email' => 'thabo@glow.co.za', 'phone' => '+27821234568', 'first_name' => 'Thabo']);
    $this->member($staff, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($staff->id, 'Thabo', 'staff'));
    expect($this->actingAs($staff)->get($this->base.'/owed')->status())->toBeIn([403, 404]);
});

it('shows the invoice in the customer’s language, and keeps its signed link valid when they switch', function () {
    TranslationStore::save('fr', 'Item', 'Article', 'reviewed', null);
    TranslationStore::save('fr', 'Invoice :number', 'Facture :number', 'reviewed', null);
    ($this->invoice)(['send' => true]);
    $link = ($this->in)(fn () => app(Invoices::class)->link(Invoice::query()->sole()));
    $this->get($link, ['Accept-Language' => 'fr-FR'])->assertOk()->assertSee('<html lang="fr">', false)->assertSee('Facture INV-0001')->assertSee('Article')->assertSee('Français');
    $this->get($link.'&lang=en')->assertOk()->assertSee('Invoice INV-0001')->assertCookie('wurk_lang', 'en', false);
});

it('charges the tax of the business’s country on the invoice’s day, and shows its name and rate on the form (25.1)', function () {
    $this->glow->forceFill(['country' => 'NA'])->save();
    TaxRate::query()->where('country_code', 'NA')->update(['confirmed_at' => now()]);
    TaxRate::query()->create(['country_code' => 'NA', 'rate_bp' => 1700, 'starts_on' => now()->subDay()->toDateString()]); // not confirmed: not used
    ($this->invoice)()->assertSessionHasNoErrors();
    $i = ($this->in)(fn () => Invoice::query()->sole());
    expect([$i->vat_bp, $i->vat_cents, $i->total_cents])->toBe([1500, 18000, 138000]);
    $this->actingAs($this->owner)->get($this->base.'/owed')->assertInertia(fn (AssertableInertia $p) => $p->where('tax', ['name' => 'VAT', 'rate' => 15]));
});
