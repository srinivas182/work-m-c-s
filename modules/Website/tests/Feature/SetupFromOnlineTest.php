<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\SetupInterview;
use Wurk\Website\Models\SiteAsset;
use Wurk\Website\Support\OnlineImport;

/*
 * Sprint 23.2: set up from what the business already has online — a Google listing (Places API) or a public page — read
 * only from public information, safely fetched, and checked by the owner before anything is set up.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    SimulatedDriver::$nextAnswer = null;
    OnlineImport::$resolver = fn (string $host) => match ($host) {
        'intranet.glow.test' => ['10.0.0.5'],
        'evil.example' => ['127.0.0.1'],
        default => ['93.184.216.34'],
    };
    $this->createShards(1);
    $this->artisan('website:install-templates', ['--only' => ['salon-hair-braids']]);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Zodwa', 'owner'));
    $this->import = fn (string $link) => $this->actingAs($this->owner)->post($this->app('/glow-salon/website/set-up/import'), ['link' => $link]);
});

afterEach(fn () => OnlineImport::$resolver = null);

it('fills the answers from the Google listing, with no review text, for the owner to check', function () {
    ($this->import)('Glow Salon Soweto')->assertSessionHasErrors(['link' => 'Google listings can’t be read yet. Paste your website or Facebook page instead, or answer the questions.']);
    app(ProviderSettings::class)->save('places.google', 'google', ['api_key' => 'places-key'], [], true, null);
    Http::fake([
        'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Glow+Salon/@-26.2,27.9,17z']),
        'www.google.com/maps/*' => Http::response('<html></html>'),
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [[
            'displayName' => ['text' => 'Glow Salon'], 'formattedAddress' => '12 Vilakazi St, Orlando West, Soweto, 1804',
            'nationalPhoneNumber' => '082 555 0101', 'primaryTypeDisplayName' => ['text' => 'Hair salon'],
            'addressComponents' => [['longText' => 'Soweto', 'types' => ['sublocality', 'political']]],
            'regularOpeningHours' => ['periods' => [
                ['open' => ['day' => 1, 'hour' => 8, 'minute' => 0], 'close' => ['day' => 1, 'hour' => 17, 'minute' => 0]],
                ['open' => ['day' => 6, 'hour' => 9, 'minute' => 0], 'close' => ['day' => 6, 'hour' => 13, 'minute' => 30]],
            ]],
        ]]]),
    ]);

    ($this->import)('https://maps.app.goo.gl/AbC123')->assertSessionHas('status', 'Filled in 4 of 6 answers. Check each one, change anything that’s wrong, and answer the rest.');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'places:searchText') && $r['textQuery'] === 'Glow Salon' && $r->hasHeader('X-Goog-Api-Key', 'places-key')
        && ! str_contains($r->header('X-Goog-FieldMask')[0], 'reviews'));
    $i = ($this->in)(fn () => SetupInterview::query()->sole());
    expect($i->answers)->toEqual(['business' => 'From your Google listing', 'area' => 'From your Google listing', 'contact' => 'From your Google listing', 'hours' => 'From your Google listing'])
        ->and($i->brief['address'])->toBe('12 Vilakazi St, Orlando West, Soweto, 1804')
        ->and($i->brief['area'])->toBe('Soweto')
        ->and($i->brief['hours'][0])->toEqual(['opens' => '08:00', 'closes' => '17:00'])
        ->and($i->brief['hours'][5])->toEqual(['opens' => '09:00', 'closes' => '13:30'])
        ->and($i->brief['hours'][6])->toEqual(['opens' => '', 'closes' => '']);
});

it('reads a public page’s preview and text with WURK AI, and fetches only public https addresses', function () {
    Http::fake([
        'www.facebook.com/glowsalon' => Http::response('<html><head><meta property="og:title" content="Glow Salon"><meta property="og:description" content="Braids &amp; nails in Soweto. Knotless braids R450."><meta property="og:image" content="/img/cover.jpg"></head><body>Call 082 555 0101</body></html>'),
        'evil.example/*' => Http::response('secret'),
        'redirector.example/*' => Http::response('', 302, ['Location' => 'https://intranet.glow.test/admin']),
    ]);
    SimulatedDriver::$nextAnswer = json_encode(['name' => 'Glow Salon', 'what' => 'Braids and nails', 'category' => 'salon-beauty', 'area' => 'Soweto', 'address' => '',
        'services' => [['name' => 'Knotless braids', 'price' => 450, 'minutes' => 0]], 'products' => [], 'hours' => [], 'phone' => '082 555 0101', 'whatsapp' => '', 'email' => '']);
    ($this->import)('https://www.facebook.com/glowsalon')->assertSessionHas('status', 'Filled in 4 of 6 answers. Check each one, change anything that’s wrong, and answer the rest.');
    $brief = ($this->in)(fn () => SetupInterview::query()->sole()->brief);
    expect($brief['services'])->toEqual([['name' => 'Knotless braids', 'price_cents' => 45000, 'minutes' => 60]])
        ->and($brief['category'])->toBe('salon-beauty')
        ->and($brief['photos'])->toEqual([['url' => 'https://www.facebook.com/img/cover.jpg', 'source' => 'www.facebook.com', 'chosen' => false]]); // offered, not used until picked

    ($this->import)('http://www.facebook.com/glowsalon')->assertSessionHasErrors(['link' => 'Paste a public https link to your page.']);
    ($this->import)('https://evil.example/x')->assertSessionHasErrors(['link' => 'Paste a public https link to your page.']);
    ($this->import)('https://redirector.example/go')->assertSessionHasErrors('link');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example') || str_contains($r->url(), 'intranet.glow.test'));
});

it('offers only the business’s own photos, uses the ones the owner picks, and links to Google reviews without copying them', function () {
    Storage::fake((string) config('wurk.site_assets_disk'));
    $img = imagecreatetruecolor(1200, 800);
    imagefilledrectangle($img, 0, 0, 1199, 799, (int) imagecolorallocate($img, 200, 120, 90));
    ob_start();
    imagejpeg($img);
    $jpeg = (string) ob_get_clean();
    app(ProviderSettings::class)->save('places.google', 'google', ['api_key' => 'places-key'], [], true, null);
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [[
            'displayName' => ['text' => 'Glow Salon'], 'formattedAddress' => '12 Vilakazi St, Soweto', 'googleMapsUri' => 'https://maps.google.com/?cid=123',
            'photos' => [
                ['name' => 'places/abc/photos/own1', 'authorAttributions' => [['displayName' => 'Glow Salon']]],
                ['name' => 'places/abc/photos/customer', 'authorAttributions' => [['displayName' => 'Thandi M']]],
            ],
        ]]]),
        'places.googleapis.com/v1/places/abc/photos/own1/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/own1']),
        'lh3.googleusercontent.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
    ]);
    ($this->import)('Glow Salon Soweto')->assertSessionHasNoErrors();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'photos/customer'));
    $this->actingAs($this->owner)->get($this->app('/glow-salon/website/set-up'))->assertInertia(fn ($p) => $p->where('photos', [['source' => 'your Google listing', 'chosen' => false]])->where('reviews', true));
    $this->actingAs($this->owner)->get($this->app('/glow-salon/website/set-up/photo/0'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->owner)->get($this->app('/glow-salon/website/set-up/photo/1'))->assertNotFound();
    $this->actingAs($this->owner)->post($this->app('/glow-salon/website/set-up/photos'), ['chosen' => [3]])->assertSessionHasErrors('chosen.0');
    $this->actingAs($this->owner)->post($this->app('/glow-salon/website/set-up/photos'), ['chosen' => [0]])->assertSessionHasNoErrors();

    ($this->in)(function () {
        $i = SetupInterview::query()->sole();
        $i->update(['answers' => [...$i->answers, 'offer' => 'Braids', 'hours' => 'Mon 9-5', 'contact' => 'none', 'online' => 'Website only'], 'brief' => [...$i->brief, 'category' => 'salon-beauty']]);
    });
    $this->actingAs($this->owner)->get($this->app('/glow-salon/website/set-up'))->assertInertia(fn ($p) => $p->where('preview', fn ($lines) => collect($lines)->contains('Use 1 of your own photo in the opening banner and gallery')
        && collect($lines)->contains('Link to your reviews on Google (they stay on Google; nothing is copied)')));
    $this->actingAs($this->owner)->post($this->app('/glow-salon/website/set-up/apply'))->assertSessionHas('status', fn (string $s) => str_contains($s, '1 of your photos added') && str_contains($s, 'Linked to your Google reviews'));
    ($this->in)(function () {
        $asset = SiteAsset::query()->sole();
        $home = collect(json_decode(Page::query()->where('is_home', true)->sole()->draft, true)['blocks'])->keyBy('type');
        expect($asset->ai_generated)->toBeFalse()
            ->and($home['Hero']['props']['images'][0]['asset'])->toBe($asset->id)
            ->and($home['Gallery']['props']['images'])->toHaveCount(1)
            ->and($home['Testimonials']['props']['moreHref'])->toBe('https://maps.google.com/?cid=123');
    });
});

it('reads Google’s opening periods Monday first', function () {
    expect(OnlineImport::hoursFromPeriods([['open' => ['day' => 0, 'hour' => 10], 'close' => ['day' => 0, 'hour' => 14]]])[6])->toBe(['opens' => '10:00', 'closes' => '14:00'])
        ->and(OnlineImport::hoursFromPeriods([]))->toBeNull()
        ->and(OnlineImport::publicHost('10.1.2.3'))->toBeFalse()
        ->and(OnlineImport::publicHost('8.8.8.8'))->toBeTrue();
});
