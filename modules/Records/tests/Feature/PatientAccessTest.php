<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Records\Mail\RecordCodeMail;
use Wurk\Records\Mail\RecordLinkMail;
use Wurk\Records\Models\Patient;
use Wurk\Records\Support\PatientAccess;
use Wurk\Records\Support\Records;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('records');
    Mail::fake();
    $this->createShards(1);
    $this->clinic = $this->createTenant('mamelodi-physio', name: 'Mamelodi Physio');
    $p = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->clinic->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->clinic, $fn);
    [$this->lerato, $this->sipho, $this->v1, $this->v2, $this->other] = ($this->in)(function () {
        BookingSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'health_practice' => true]);
        $lerato = Patient::query()->create(['name' => 'Lerato Mokoena', 'email' => 'lerato@example.com']);
        $sipho = Patient::query()->create(['name' => 'Sipho Dlamini', 'email' => 'sipho@example.com']);
        $records = app(Records::class);
        $v1 = $records->upload($lerato, 'Assessment', UploadedFile::fake()->createWithContent('a.txt', 'grade 2'), 'Dr Naidoo');
        $v2 = $records->upload($lerato, 'Assessment', UploadedFile::fake()->createWithContent('a.txt', 'grade 1'), 'Dr Naidoo', corrects: $v1, note: 'Grade corrected');
        $other = $records->upload($sipho, 'X-ray report', UploadedFile::fake()->createWithContent('x.txt', 'Sipho only'), 'Dr Naidoo');

        return [$lerato, $sipho, $v1, $v2, $other];
    });
    $this->site = 'http://mamelodi-physio.'.config('wurk.domains.root');
    $this->open = function () {
        $url = ($this->in)(fn () => app(PatientAccess::class)->sendLink($this->lerato, 'Dr Naidoo'));

        return '/records/'.basename($url);
    };
    $this->code = function () {
        $code = '';
        Mail::assertSent(RecordCodeMail::class, function (RecordCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    };
});

it('lets a patient see only their own documents, after a one-time code — every access logged', function () {
    $path = ($this->open)();
    Mail::assertSent(RecordLinkMail::class, fn (RecordLinkMail $m) => $m->hasTo('lerato@example.com') && ! str_contains($m->url, 'Assessment'));
    $this->get($this->site.$path)->assertOk()->assertSee('Send me a code')->assertDontSee('Assessment')->assertHeader('Cache-Control', 'no-store, private');

    $this->post($this->site.$path.'/code')->assertRedirect($path.'?sent=1');
    $this->post($this->site.$path.'/verify', ['code' => '000000'])->assertRedirect($path.'?error=wrong');
    $this->get($this->site.$path.'/'.$this->v2->id)->assertForbidden(); // no code, no file (checked before any cookie exists: the test client keeps cookies)
    $ok = $this->post($this->site.$path.'/verify', ['code' => ($this->code)()]);
    $cookie = collect($ok->headers->getCookies())->first();
    expect($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('strict')->and($cookie->getPath())->toBe($path);

    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get($this->site.$path)->assertOk()
        ->assertSee('Hello Lerato Mokoena')->assertSee('version 2')->assertSee('an earlier version')->assertDontSee('X-ray report');
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get($this->site.$path.'/'.$this->v2->id)->assertOk()->assertSee('grade 1');
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get($this->site.$path.'/'.$this->other->id)->assertNotFound(); // another patient's

    $actions = ($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('record_access_logs')->where('patient_id', $this->lerato->id)->orderBy('id')->pluck('action')->all());
    expect($actions)->toBe(['upload', 'correct', 'link_sent', 'code_sent', 'code_failed', 'code_ok', 'view']);
});

it('locks the link after five wrong codes', function () {
    $path = ($this->open)();
    $this->post($this->site.$path.'/code');
    foreach (range(1, 4) as $i) {
        $this->post($this->site.$path.'/verify', ['code' => '000000'])->assertRedirect($path.'?error=wrong');
    }
    $this->post($this->site.$path.'/verify', ['code' => '000000'])->assertRedirect($path.'?error=locked');
    $this->get($this->site.$path)->assertStatus(410)->assertSee('This link has expired');
});

it('isn’t there unless the business is a health practice', function () {
    $path = ($this->open)();
    ($this->in)(fn () => BookingSettings::query()->update(['health_practice' => false]));
    $this->get($this->site.$path)->assertNotFound();
});

it('never serves a file without an unlocked session', function () {
    $path = ($this->open)();
    $this->get($this->site.$path.'/'.$this->v1->id)->assertForbidden();
    $this->withUnencryptedCookie('rec_'.substr(hash('sha256', basename($path)), 0, 16), 'forged')->get($this->site.$path.'/'.$this->v1->id)->assertForbidden();
});

it('limits per link and visitor — page loads don’t use up the code limit', function () {
    $path = ($this->open)();
    foreach (range(1, 8) as $i) {
        $this->get($this->site.$path)->assertOk();
    }
    $this->post($this->site.$path.'/code')->assertRedirect($path.'?sent=1'); // not "Too Many Requests"
});
