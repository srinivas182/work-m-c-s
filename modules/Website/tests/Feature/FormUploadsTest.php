<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Enquiry;
use Wurk\Website\Models\EnquiryFile;
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
