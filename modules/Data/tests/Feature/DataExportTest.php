<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\CustomRoles;
use Wurk\Access\Actions\Team;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Data\Mail\DataMail;
use Wurk\Data\Models\DataExport;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\DataExports;
use Wurk\Records\Models\Patient;
use Wurk\Records\Support\Records;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->withSession(['auth.confirmed_at' => now()->getTimestamp()]); // "confirm it's you" (18.2b) is tested in Identity
    Storage::fake('records');
    Storage::fake((string) config('filesystems.default'));
    Storage::fake((string) config('wurk.site_assets_disk'));
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings', 'shop', 'marketing'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->ctx = app(TenantContext::class);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->member($this->staff, $this->glow);
    $this->ctx->run($this->glow, function () {
        app(Team::class)->add($this->owner->id, 'Thandi', 'owner');
        app(Team::class)->add($this->staff->id, 'Sam', 'staff');
        $db = DB::connection($this->ctx->connectionName());
        $db->table('contacts')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->ctx->id(), 'name' => 'Lerato Mokoena', 'phone' => '+27830000001', 'email' => 'lerato@example.com',
            'notes' => '=HYPERLINK("http://evil")', 'tags' => json_encode(['vip']), 'created_at' => now(), 'updated_at' => now()]);
        $patient = Patient::query()->create(['name' => 'Sipho Dlamini', 'email' => 'sipho@example.com']);
        app(Records::class)->upload($patient, 'Assessment', UploadedFile::fake()->createWithContent('report.pdf', 'Lower back strain'), 'Dr Naidoo');
    });
    $this->page = $this->app('/glow-salon/settings/your-data');
    $this->zip = function (): ZipArchive {
        $e = $this->ctx->run($this->glow, fn () => DataExport::query()->where('status', 'ready')->latest()->firstOrFail());
        $tmp = (string) tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $this->ctx->run($this->glow, fn () => $this->ctx->disk()->get((string) $e->path)));
        $zip = new ZipArchive;
        $zip->open($tmp);

        return $zip;
    };
});

it('builds everything into one ZIP in the background and emails a 24-hour link', function () {
    $this->actingAs($this->staff)->get($this->page)->assertNotFound(); // owners (data.export) only
    $this->actingAs($this->owner)->post($this->page.'/export')->assertSessionHas('status', 'We’re building your export. We’ll email you a download link (it works for 24 hours).');

    $zip = ($this->zip)();
    $readme = (string) $zip->getFromName('README.txt');
    expect($readme)->toContain('Your data from WURK — Glow Salon')->toContain('- Customers: 1 row')->toContain('- Patients (health records): 1 row')
        ->and($zip->locateName('customers.csv'))->not->toBeFalse()->and($zip->locateName('bookings.csv'))->not->toBeFalse();
    $csv = (string) $zip->getFromName('customers.csv');
    expect($csv)->toContain('Lerato Mokoena')->toContain('lerato@example.com')
        ->not->toContain('tenant_id')                     // never internal columns
        ->toContain("'=HYPERLINK");                       // formulas kept as text
    // Health documents, decrypted for the owner — and the read logged like any other.
    $docs = array_values(array_filter(array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn ($n) => str_starts_with((string) $n, 'files/patient-documents/')));
    expect($docs)->toHaveCount(1)->and($zip->getFromName($docs[0]))->toBe('Lower back strain')
        ->and($this->ctx->run($this->glow, fn () => DB::connection($this->ctx->connectionName())->table('record_access_logs')->where('action', 'export')->count()))->toBe(1);

    $export = $this->ctx->run($this->glow, fn () => DataExport::query()->firstOrFail());
    Mail::assertSent(DataMail::class, fn (DataMail $m) => $m->hasTo('owner@glow.co.za') && $m->subjectLine === 'Your WURK data export is ready' && str_contains($m->link, 'signature='));
    expect(DB::connection($this->ctx->run($this->glow, fn () => $this->ctx->connectionName()))->table('audit_events')->whereIn('action', ['data.export_requested', 'data.export_ready'])->count())->toBe(2);

    $this->actingAs($this->owner)->get($this->page)->assertInertia(fn (Assert $p) => $p->component('Data::YourData')->where('exports.0.status', 'ready')
        ->where('exports.0.contents.tables.Customers', 1));
});

it('leaves out what the person may not see, and any secret-looking column', function () {
    // A custom role with data.export but not records.view: no patients in the ZIP.
    $this->ctx->run($this->glow, function () {
        $role = app(CustomRoles::class)->createBusinessRole('Office', ['data.export', 'contacts.view']);
        $member = collect(app(TeamDirectory::class)->roster())->firstWhere('identity_id', $this->staff->id);
        app(TeamDirectory::class)->setRole((string) $member['id'], 'custom:'.$role->id);
    });
    app(DataExports::class)->table('test-secrets', 'Test', null, fn () => [['name' => 'x', 'api_key' => 'sk_live_1', 'reset_token' => 't', 'password_hash' => 'h']]);
    $this->actingAs($this->staff)->post($this->page.'/export')->assertSessionHas('status');
    $zip = ($this->zip)();
    expect($zip->locateName('patients.csv'))->toBeFalse()->and($zip->locateName('orders.csv'))->toBeFalse()
        ->and($zip->locateName('customers.csv'))->not->toBeFalse()
        ->and((string) $zip->getFromName('test-secrets.csv'))->toContain('name')->not->toContain('sk_live_1')->not->toContain('reset_token')->not->toContain('password');
});

it('downloads only through the signed link, while it lasts — and deletes the file after 24 hours', function () {
    $this->actingAs($this->owner)->post($this->page.'/export');
    $e = $this->ctx->run($this->glow, fn () => DataExport::query()->firstOrFail());
    $signed = URL::temporarySignedRoute('tenant.data.download', now()->addDay(), ['tenant' => 'glow-salon', 'export' => $e->id]);
    $this->actingAs($this->owner)->get($this->page.'/export/'.$e->id)->assertForbidden();                  // no signature
    $this->actingAs($this->owner)->get($signed)->assertOk()->assertDownload('wurk-glow-salon-'.now()->format('Y-m-d').'.zip');
    $this->actingAs($this->staff)->get($signed)->assertNotFound();                                        // signed in, but not allowed

    $this->travel(25)->hours();
    $this->artisan('data:purge-exports')->expectsOutput('Deleted 1 expired exports.');
    $e = $this->ctx->run($this->glow, fn () => DataExport::query()->firstOrFail());
    expect($e->status)->toBe('expired')->and($e->path)->toBeNull();
});

it('builds one at a time, and at most three a day', function () {
    $this->ctx->run($this->glow, fn () => DataExport::query()->create(['requested_by' => $this->owner->id, 'status' => 'queued']));
    $this->actingAs($this->owner)->post($this->page.'/export')->assertSessionHas('warning', 'An export is already being built — we’ll email you when it’s ready.');
    $this->ctx->run($this->glow, fn () => DataExport::query()->update(['status' => 'ready']));
    $this->ctx->run($this->glow, fn () => DataExport::query()->create(['requested_by' => $this->owner->id, 'status' => 'ready']));
    $this->ctx->run($this->glow, fn () => DataExport::query()->create(['requested_by' => $this->owner->id, 'status' => 'failed']));
    $this->actingAs($this->owner)->post($this->page.'/export')->assertSessionHas('warning', 'That’s 3 exports in a day — please try again tomorrow.');
});
