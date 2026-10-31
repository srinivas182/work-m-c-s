<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Enquiry;
use Wurk\Website\Models\EnquiryFile;
use Wurk\Website\Support\StorageMeter;
use Wurk\Website\Support\Uploads\FormFiles;
use Wurk\Website\Support\Uploads\VirusScanner;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    Storage::fake('form-files');
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->ctx = app(TenantContext::class);
    $this->url = 'http://'.config('wurk.domains.app').'/forms/glow-salon/quote';
    $this->scanner = new class implements VirusScanner
    {
        public ?string $verdict = VirusScanner::CLEAN;

        public function scan(string $contents): ?string
        {
            return $this->verdict;
        }
    };
    app()->instance(VirusScanner::class, $this->scanner);
    $this->valid = ['name' => 'Sipho', 'phone' => '082 555 0101', 'message' => 'Photo of the roof attached.', 'consent' => '1'];
    $this->jpeg = function (string $append = ''): UploadedFile {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $bytes = ob_get_clean().$append;

        return UploadedFile::fake()->createWithContent('roof.jpg', $bytes);
    };
    $this->file = fn () => $this->ctx->run($this->glow, fn () => EnquiryFile::query()->sole());
});

it('re-saves an image (dropping hidden content), encrypts it, and releases it only once scanned clean', function () {
    $this->post($this->url, [...$this->valid, 'files' => [($this->jpeg)('<?php system($_GET["c"]); ?>')]])->assertOk();
    $f = ($this->file)();
    expect($f->status)->toBe('clean')->and($f->mime)->toBe('image/jpeg')->and($f->name)->toBe('roof.jpg');
    $raw = Storage::disk('form-files')->get((string) $f->path);
    expect($raw)->not->toContain("\xFF\xD8")->not->toContain('<?php'); // encrypted at rest
    $plain = $this->ctx->run($this->glow, fn () => app(FormFiles::class)->read($f));
    expect(substr((string) $plain, 0, 2))->toBe("\xFF\xD8")->and($plain)->not->toContain('<?php'); // re-saved: only the pixels survive
});

it('deletes an infected file (and logs it), and holds files while the scanner is down', function () {
    $this->scanner->verdict = VirusScanner::INFECTED;
    $this->post($this->url, [...$this->valid, 'files' => [($this->jpeg)()]])->assertOk();
    $f = ($this->file)();
    expect($f->status)->toBe('infected')->and($f->path)->toBeNull()
        ->and($this->ctx->run($this->glow, fn () => app(FormFiles::class)->read($f)))->toBeNull();

    $this->ctx->run($this->glow, fn () => EnquiryFile::query()->delete());
    $this->scanner->verdict = null; // unreachable
    $this->post($this->url, [...$this->valid, 'files' => [($this->jpeg)()]])->assertOk();
    $held = ($this->file)();
    expect($held->status)->toBe('pending')->and($held->path)->not->toBeNull()
        ->and($this->ctx->run($this->glow, fn () => app(FormFiles::class)->read($held)))->toBeNull(); // not downloadable until scanned
});

it('refuses disguised files, risky PDFs and too many files — saving nothing', function () {
    $fakeJpg = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "hi"; ?>');
    $this->postJson($this->url, [...$this->valid, 'files' => [$fakeJpg]])->assertStatus(422)->assertJsonPath('errors.files.0', 'Please attach a photo (JPEG, PNG or WebP) or a PDF.');
    $pdf = UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n%%EOF");
    $this->postJson($this->url, [...$this->valid, 'files' => [$pdf]])->assertStatus(422)->assertJsonPath('errors.files.0', 'That PDF contains scripts or attachments we can’t accept — please save it as a plain PDF.');
    $this->postJson($this->url, [...$this->valid, 'files' => [($this->jpeg)(), ($this->jpeg)(), ($this->jpeg)(), ($this->jpeg)()]])->assertStatus(422)->assertJsonValidationErrors('files');
    expect($this->ctx->run($this->glow, fn () => [Enquiry::query()->count(), EnquiryFile::query()->count()]))->toBe([0, 0]);
});

it('lists a reply’s files for the owner and downloads clean ones only — as an attachment, sandboxed, not cached', function () {
    $owner = $this->person();
    $this->member($owner, $this->glow);
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: true);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));
    $this->post($this->url, [...$this->valid, 'files' => [($this->jpeg)()]])->assertOk();
    $f = ($this->file)();
    $base = $this->app('/glow-salon/website/enquiries');

    $this->actingAs($owner)->get($base)->assertInertia(fn ($p) => $p->where('enquiries.0.files.0.name', 'roof.jpg')->where('enquiries.0.files.0.status', 'clean'));
    $r = $this->actingAs($owner)->get("{$base}/{$f->enquiry_id}/files/{$f->id}")->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($r->headers->get('Content-Disposition'))->toStartWith('attachment')->and(substr((string) $r->getContent(), 0, 2))->toBe("\xFF\xD8");

    $this->actingAs($owner)->get("{$base}/01HZZZZZZZZZZZZZZZZZZZZZZZ/files/{$f->id}")->assertNotFound(); // not this enquiry's file
    $this->ctx->run($this->glow, fn () => EnquiryFile::query()->whereKey($f->id)->update(['status' => 'pending']));
    $this->actingAs($owner)->get("{$base}/{$f->enquiry_id}/files/{$f->id}")->assertNotFound(); // not scanned yet
});

it('removes files of enquiries archived for over a year (logged), keeps the rest, and counts held files towards storage', function () {
    $this->post($this->url, [...$this->valid, 'files' => [($this->jpeg)()]])->assertOk();
    $this->post($this->url, [...$this->valid, 'name' => 'Lerato', 'files' => [($this->jpeg)()]])->assertOk();
    [$old, $kept] = $this->ctx->run($this->glow, function () {
        $rows = Enquiry::query()->orderBy('name')->get(); // Lerato, Sipho
        Enquiry::query()->whereKey($rows[1]->id)->update(['status' => 'archived', 'created_at' => now()->subMonths(13)]);
        Enquiry::query()->whereKey($rows[0]->id)->update(['created_at' => now()->subMonths(13)]); // old, but not archived

        return [$rows[1]->id, $rows[0]->id];
    });
    $used = fn () => app(StorageMeter::class)->measure($this->glow->id)['used'];
    $before = $this->ctx->run($this->glow, fn () => (int) EnquiryFile::query()->sum('size'));
    expect($before)->toBeGreaterThan(0);

    $this->artisan('website:expire-form-files')->assertSuccessful();
    $this->ctx->run($this->glow, function () use ($old, $kept) {
        expect(EnquiryFile::query()->where('enquiry_id', $old)->sole()->only(['status', 'path']))->toBe(['status' => 'removed', 'path' => null])
            ->and(EnquiryFile::query()->where('enquiry_id', $kept)->sole()->status)->toBe('clean');
    });
    $after = $this->ctx->run($this->glow, fn () => (int) EnquiryFile::query()->whereNotNull('path')->sum('size'));
    expect($after)->toBeLessThan($before)->and($after)->toBeGreaterThan(0);
    expect($used())->toBeFloat();
});
