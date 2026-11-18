<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Drivers\SimulatedSpeechDriver;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Models\Product;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\SetupInterview;
use Wurk\Website\Models\Site;

/*
 * Sprint 23.1: set up by talking. Six questions, typed or spoken; WURK AI only reads the answers (nothing invented, every
 * value checked); the owner sees what was understood and the full change list, and nothing happens until they confirm.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('public');
    SimulatedDriver::$answers = [];
    SimulatedDriver::$nextAnswer = null;
    $this->createShards(1);
    $this->artisan('website:install-templates', ['--only' => ['salon-hair-braids']]);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings', 'shop', 'ai-assistant'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Zodwa', 'owner'));
    $this->base = $this->app('/glow-salon/website/set-up');
    // What WURK AI reads from each answer (the simulated provider answers in order).
    $this->readings = [
        'business' => ['name' => 'Glow Salon', 'what' => 'Hair braiding and nails', 'category' => 'salon-beauty'],
        'area' => ['area' => 'Soweto', 'address' => '12 Vilakazi St, Orlando West'],
        'offer' => ['services' => [['name' => 'Knotless braids', 'price' => 450, 'minutes' => 180], ['name' => 'Gel nails', 'price' => '250', 'minutes' => 'long']],
            'products' => [['name' => 'Braiding hair', 'price' => 85], ['name' => 'Gift voucher', 'price' => 'any']]],
        'hours' => ['hours' => [['opens' => '08:00', 'closes' => '17:00'], ['opens' => '08:00', 'closes' => '17:00'], ['opens' => '08:00', 'closes' => '17:00'],
            ['opens' => '08:00', 'closes' => '17:00'], ['opens' => '08:00', 'closes' => '17:00'], ['opens' => '09:00', 'closes' => '13:00'], ['opens' => '25:00', 'closes' => '']]],
        'contact' => ['phone' => '082 555 0101', 'whatsapp' => '082 555 0101', 'email' => 'not an email'],
        'online' => ['bookings' => true, 'shop' => true],
    ];
    $this->say = fn (string $key, string $text) => $this->actingAs($this->owner)->post("{$this->base}/answer", ['key' => $key, 'text' => $text]);
});

it('asks one question at a time and shows what it understood, checking every value', function () {
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Website::SetupTalk')
        ->where('next', 'business')->where('preview', null)->has('questions', 6));

    SimulatedDriver::$nextAnswer = 'Here you go: '.json_encode($this->readings['business']);
    ($this->say)('business', 'Glow Salon — we do braids and nails');
    SimulatedDriver::$nextAnswer = json_encode($this->readings['offer']);
    ($this->say)('offer', 'Knotless braids R450, three hours. Gel nails 250. We sell braiding hair for R85 and vouchers.');
    SimulatedDriver::$nextAnswer = json_encode($this->readings['hours']);
    ($this->say)('hours', 'Weekdays 8 to 5, Saturday 9 to 1');
    SimulatedDriver::$nextAnswer = json_encode($this->readings['contact']);
    ($this->say)('contact', '082 555 0101 for calls and WhatsApp');

    $brief = ($this->in)(fn () => SetupInterview::query()->sole()->brief); // a JSON column: MySQL reorders keys, so toEqual
    expect($brief['services'])->toEqual([['name' => 'Knotless braids', 'price_cents' => 45000, 'minutes' => 180], ['name' => 'Gel nails', 'price_cents' => 25000, 'minutes' => 60]])
        ->and($brief['products'])->toEqual([['name' => 'Braiding hair', 'price_cents' => 8500], ['name' => 'Gift voucher', 'price_cents' => 0]])
        ->and($brief['hours'][5])->toEqual(['opens' => '09:00', 'closes' => '13:00'])
        ->and($brief['hours'][6])->toEqual(['opens' => '', 'closes' => '']) // "25:00" isn't a time: closed, not invented
        ->and($brief['email'])->toBe('');

    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->where('next', 'area')
        ->where('questions.0.understood', 'Glow Salon — Hair braiding and nails (Salon & beauty)')
        ->where('questions.2.understood', 'Knotless braids R450.00, Gel nails R250.00, Braiding hair R85.00, Gift voucher')
        ->where('questions.3.understood', 'Mon–Fri 08:00–17:00, Sat 09:00–13:00')
        ->where('questions.4.understood', '082 555 0101, WhatsApp 082 555 0101'));
    ($this->say)('area', '')->assertSessionHasErrors('text');
});

it('takes a voice note as an answer', function () {
    SimulatedSpeechDriver::$script = [['text' => 'Glow Salon, ngenza izinwele', 'language' => 'zu', 'confidence' => 0.9]];
    SimulatedDriver::$nextAnswer = json_encode($this->readings['business']);
    $this->actingAs($this->owner)->post("{$this->base}/voice", ['key' => 'business', 'audio' => UploadedFile::fake()->create('answer.webm', 40, 'audio/webm')])->assertSessionHasNoErrors();
    expect(($this->in)(fn () => SetupInterview::query()->sole()->answers['business']))->toBe('Glow Salon, ngenza izinwele');
});

it('shows the full change list, then sets up the website, bookable services, hours and products only when confirmed', function () {
    foreach ($this->readings as $key => $reading) {
        SimulatedDriver::$nextAnswer = json_encode($reading);
        ($this->say)($key, 'answer '.$key)->assertSessionHasNoErrors();
    }
    expect(($this->in)(fn () => Site::query()->count()))->toBe(0); // nothing yet
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->where('next', null)
        ->where('preview', fn ($lines) => collect($lines)->contains('Contact details: 082 555 0101, WhatsApp 082 555 0101, 12 Vilakazi St, Orlando West')
            && collect($lines)->contains('Opening hours: Mon–Fri 08:00–17:00, Sat 09:00–13:00')
            && collect($lines)->contains('Customers can book 2 services online with you')
            && collect($lines)->contains('Put 1 product on sale in your online shop') // the voucher has no price
            && str_starts_with((string) $lines[0], 'Make your website from the “')));

    $this->actingAs($this->owner)->post("{$this->base}/apply")->assertRedirect()->assertSessionHas('status', fn (string $s) => str_starts_with($s, 'All set up: Website made from'));
    ($this->in)(function () {
        $site = Site::query()->sole();
        $contact = Page::query()->where('slug', 'contact')->sole();
        $blocks = collect(json_decode($contact->draft, true)['blocks'])->keyBy('type');
        expect($site->settings['checklist']['hours'])->toBeTrue()
            ->and($blocks['Contact']['props']['phone'])->toBe('082 555 0101')
            ->and($blocks['Contact']['props']['address'])->toBe('12 Vilakazi St, Orlando West')
            ->and($blocks['OpeningHours']['props']['hours'][0])->toBe(['opens' => '08:00', 'closes' => '17:00'])
            ->and(BookingService::query()->orderBy('name')->pluck('price_cents', 'name')->all())->toBe(['Gel nails' => 25000, 'Knotless braids' => 45000])
            ->and(BookingHours::query()->count())->toBe(6)
            ->and(Product::query()->pluck('name')->all())->toBe(['Braiding hair'])
            ->and(SetupInterview::query()->sole()->status)->toBe('done')
            ->and(collect(json_decode(Page::query()->where('is_home', true)->sole()->draft, true)['blocks'])->firstWhere('type', 'ServicesPrices')['props']['items'][0]['name'])->toBe('Knotless braids');
    });
});

it('refuses to set up before every question is answered, and is for people who edit the website', function () {
    $this->actingAs($this->owner)->post("{$this->base}/apply")->assertSessionHasErrors(['answer' => 'Answer every question first.']);
    $staff = $this->person(['first_name' => 'Sipho', 'email' => 'sipho@glow.co.za', 'phone' => '+27825550101']);
    $this->member($staff, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($staff->id, 'Sipho', 'staff'));
    expect($this->actingAs($staff)->get($this->base)->status())->toBeIn([403, 404]);
});
