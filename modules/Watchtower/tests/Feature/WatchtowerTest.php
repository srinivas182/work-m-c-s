<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Health\Contracts\CheckResult;
use Wurk\Health\Contracts\HealthCheck;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Watchtower\Http\Controllers\ClientErrorController;
use Wurk\Watchtower\Mail\IncidentMail;
use Wurk\Watchtower\Mail\WatchtowerDigestMail;
use Wurk\Watchtower\Models\Incident;
use Wurk\Watchtower\Support\Mask;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

/** One place in the code that fails, like a real bug (the line is part of what makes two errors "the same"). */
function wtFail(string $message): void
{
    report(new LogicException($message));
}

beforeEach(function () {
    Mail::fake();
    config(['wurk.watchtower.recipients' => ['hq@example.test'], 'wurk.watchtower.ai' => false]); // recipients: set per deployment (WATCHTOWER_EMAIL), then in Admin
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->quiet = $this->createTenant('quiet-cafe', name: 'Quiet Cafe');
    $this->engineer = $this->staffWithRole('engineering', 'eng@wurk.co.za');
    $this->page = $this->admin('/watchtower');
    Route::middleware('web')->get('/__wt/checkout/{order}', fn (string $order) => throw new RuntimeException("Payment for order {$order} by lerato@example.com (+27 83 123 4567) failed"));
});

it('groups the same crash into one problem — personal data removed — and emails HQ once, again only when it gets much worse', function () {
    // Two businesses, same bug, different orders and customers: one problem.
    foreach ([[$this->glow, 'A1001'], [$this->quiet, 'B2002'], [$this->glow, 'A1003']] as [$t, $order]) {
        app(TenantContext::class)->run($t, fn () => $this->get("/__wt/checkout/{$order}"));
    }
    $i = Incident::query()->sole();
    expect([$i->kind, $i->occurrences, $i->businesses, $i->first_release])->toBe(['exception', 3, 2, config('wurk.release')])
        ->and($i->location)->toBe('GET __wt/checkout/{order}')
        ->and($i->message)->toBe('Payment for order A1003 by [email] ([number]) failed') // the latest
        ->and($i->severity)->toBe('high')                               // a money path
        ->and($i->file)->toBe('modules/Watchtower/tests/Feature/WatchtowerTest.php')
        ->and($i->trace)->not->toContain('lerato')->not->toContain(base_path());

    $this->artisan('watchtower:check')->assertSuccessful();
    Mail::assertSent(IncidentMail::class, fn (IncidentMail $m) => $m->hasTo('hq@example.test') && $m->reason === 'new'
        && str_contains($m->fixRequest, '## WURK fix request — '.$i->reference()) && str_contains($m->fixRequest, '3 times') && ! str_contains($m->fixRequest, 'lerato'));
    Mail::assertSentCount(1);

    // Again: no new email for the same problem.
    app(TenantContext::class)->run($this->glow, fn () => $this->get('/__wt/checkout/A1004'));
    $this->artisan('watchtower:check');
    Mail::assertSentCount(1);

    // Ten times as many: told again.
    DB::table('watchtower_incidents')->update(['occurrences' => 40]);
    $this->artisan('watchtower:check');
    Mail::assertSent(IncidentMail::class, fn (IncidentMail $m) => $m->reason === 'spike');
});

it('keeps ordinary problems for the daily report, and says "all quiet" when there are none', function () {
    $this->artisan('watchtower:digest')->expectsOutput('Report sent.');
    Mail::assertSent(WatchtowerDigestMail::class, fn (WatchtowerDigestMail $m) => $m->quiet() && $m->envelope()->subject === '[WURK Watchtower] Daily report: all quiet');

    wtFail('Something odd in a report');
    $this->artisan('watchtower:check');
    Mail::assertNotSent(IncidentMail::class);                           // normal: not emailed straight away
    $this->artisan('watchtower:digest');
    Mail::assertSent(WatchtowerDigestMail::class, fn (WatchtowerDigestMail $m) => ! $m->quiet() && count($m->new) === 1
        && str_contains($m->render(), 'LogicException: Something odd in a report'));

    config(['wurk.watchtower.recipients' => []]);
    $this->artisan('watchtower:digest')->expectsOutput('No Watchtower email is set.');
});

it('tells HQ when a problem marked fixed comes back in a later release', function () {
    wtFail('Rounding is off');
    $i = Incident::query()->sole();
    $this->actingAs($this->engineer)->put("{$this->page}/{$i->id}", ['action' => 'fixed', 'fixed_in' => '0.18.1'])
        ->assertSessionHas('status', 'Marked fixed in 0.18.1. If it happens again you’ll be told it came back.');

    config(['wurk.release' => '0.19.0']);
    wtFail('Rounding is off');
    expect(Incident::query()->sole())->status->toBe('new')->regressed_at->not->toBeNull()->last_release->toBe('0.19.0');
    $this->artisan('watchtower:check');
    Mail::assertSent(IncidentMail::class, fn (IncidentMail $m) => $m->reason === 'regression' && str_contains($m->render(), 'marked fixed in 0.18.1 and has happened again in 0.19.0'));
    $this->actingAs($this->engineer)->get("{$this->page}/{$i->id}")->assertInertia(fn (Assert $p) => $p->component('Watchtower::Incident')->where('incident.regressed', true));
});

it('checks every system each minute — critical when one fails, and says when it is back', function () {
    $this->app->instance('wt.failing', new class implements HealthCheck
    {
        public bool $ok = false;

        public function name(): string
        {
            return 'payments-gateway';
        }

        public function run(): CheckResult
        {
            return $this->ok ? CheckResult::ok() : CheckResult::failed('Timed out');
        }
    });
    $this->app->tag(['wt.failing'], HealthCheck::TAG);

    $this->artisan('watchtower:check')->expectsOutputToContain('Failing: payments-gateway.');
    $i = Incident::query()->sole();
    expect([$i->kind, $i->severity, $i->ongoing, $i->title])->toBe(['check', 'critical', true, 'Payments Gateway: Timed out']);
    expect(DB::table('watchtower_checks')->pluck('ok', 'name')->map(fn ($v) => (bool) $v)->all())
        ->toMatchArray(['payments-gateway' => false, 'database' => true, 'storage' => true])
        ->and(DB::table('watchtower_checks')->where('name', 'like', 'shard:%')->value('ok'))->toBeTruthy();
    Mail::assertSent(IncidentMail::class, fn (IncidentMail $m) => $m->reason === 'new' && str_contains($m->envelope()->subject, '[WURK Watchtower · Critical]'));

    $this->artisan('watchtower:check');                                 // still failing: no second email
    Mail::assertSentCount(1);
    app('wt.failing')->ok = true;
    $this->artisan('watchtower:check')->expectsOutputToContain('checks passing');
    expect(Incident::query()->sole())->status->toBe('resolved')->ongoing->toBeFalse();
    Mail::assertSent(IncidentMail::class, fn (IncidentMail $m) => $m->reason === 'recovered' && str_starts_with($m->envelope()->subject, '[WURK Watchtower · Recovered]'));

    // Down for a long time: one email, not one every few minutes. Down again later: told again.
    app('wt.failing')->ok = false;
    foreach (range(1, 15) as $_) {
        $this->artisan('watchtower:check');
    }
    expect(Mail::sent(IncidentMail::class, fn (IncidentMail $m) => $m->reason === 'new'))->toHaveCount(2);
    Mail::assertSentCount(3);
});

it('notices a scheduled task that stopped running, and a job that failed for good', function () {
    DB::table('watchtower_heartbeats')->insert(['task' => 'success:score', 'expression' => '37 2 * * *', 'last_started_at' => now()->subDays(2), 'last_status' => 'ok']);
    $this->artisan('watchtower:check');
    expect(Incident::query()->where('kind', 'scheduler')->sole())->severity->toBe('high')->title->toStartWith('Scheduled task “success:score” didn’t run at 02:37');

    $job = new SyncJob(app(), (string) json_encode(['displayName' => 'Wurk\\Payments\\Jobs\\SettlePayout', 'job' => 'x', 'data' => []]), 'sync', 'default');
    event(new JobFailed('sync', $job, new RuntimeException('Bank said no')));
    expect(Incident::query()->where('kind', 'job')->sole())->severity->toBe('high')->location->toBe('job Wurk\\Payments\\Jobs\\SettlePayout');
});

it('tells the uptime checker when the scheduler has stopped', function () {
    $this->getJson('/health/heartbeat')->assertStatus(503)->assertJson(['ok' => false, 'scheduler' => 'never ran']);
    DB::table('watchtower_heartbeats')->insert(['task' => 'watchtower:check', 'expression' => '* * * * *', 'last_started_at' => now()->subMinute(), 'last_status' => 'ok']);
    $this->getJson('/health/heartbeat')->assertOk()->assertJson(['ok' => true]);
    $this->travel(10)->minutes();
    $this->getJson('/health/heartbeat')->assertStatus(503)->assertJson(['scheduler' => 'stopped']);
});

it('groups browser crashes across builds, without personal data', function () {
    $stack = fn (string $hash) => "TypeError: Cannot read properties of undefined (reading 'total')\n    at Cart (https://app.wurk.co.za/build/assets/app-{$hash}.js:12:345)";
    foreach (['Ab12Cd34', 'Zz98Yy76'] as $hash) {
        $this->postJson('/_wurk/client-error', ['message' => "Cannot read properties of undefined (reading 'total') for thandi@glow.co.za", 'stack' => $stack($hash),
            'page' => 'Shop::Orders', 'kind' => 'error'], ['User-Agent' => 'Mozilla/5.0 (iPhone) Safari/604.1'])->assertNoContent();
    }
    $i = Incident::query()->sole();
    expect([$i->kind, $i->occurrences, $i->file, $i->location])->toBe(['frontend', 2, '/build/assets/app.js:12', 'page Shop::Orders'])
        ->and($i->message)->toBe("Cannot read properties of undefined (reading '?') for [email]")
        ->and($i->context)->toBe(['kind' => 'error', 'browser' => 'Safari (mobile)']);
    $this->postJson('/_wurk/client-error', ['message' => ''])->assertUnprocessable();
    expect(ClientErrorController::stripBuild('at x (https://wurk.co.za/build/assets/Orders-AbCdEf12.js?v=1:3:9)'))->toBe('at x (/build/assets/Orders.js:3:9)');
});

it('shows HQ every problem and lets engineering act on them — support can look, others can’t', function () {
    wtFail('A');
    $i = Incident::query()->sole();
    $this->actingAs($this->engineer)->get($this->page)->assertInertia(fn (Assert $p) => $p->component('Watchtower::Incidents')
        ->where('rows.data.0.title', 'LogicException: A')->where('summary.normal', 1)->where('recipients', ['hq@example.test'])->where('can_manage', true));
    $this->actingAs($this->engineer)->get("{$this->page}/{$i->id}")->assertInertia(fn (Assert $p) => $p->where('fix_request', fn ($f) => str_contains((string) $f, 'LogicException')));

    $this->actingAs($this->engineer)->put("{$this->page}/settings", ['recipients' => ['nope']])->assertSessionHasErrors(['recipients.0' => 'That isn’t an email address.']);
    $this->actingAs($this->engineer)->put("{$this->page}/settings", ['recipients' => ['HQ@wurk.co.za', 'ops@example.test']])
        ->assertSessionHas('status', 'Saved. Problem reports go to hq@wurk.co.za, ops@example.test.');
    $this->actingAs($this->engineer)->put("{$this->page}/{$i->id}", ['action' => 'acknowledge']);
    expect($i->fresh()?->status)->toBe('acknowledged');
    $this->actingAs($this->engineer)->get($this->page.'?view=all')->assertInertia(fn (Assert $p) => $p->where('rows.data.0.status', 'acknowledged')->where('recipients', ['hq@wurk.co.za', 'ops@example.test']));

    $support = $this->staffWithRole('support', 'help@wurk.co.za');
    $this->actingAs($support)->get($this->page)->assertOk();
    $this->actingAs($support)->put("{$this->page}/{$i->id}", ['action' => 'ignore'])->assertForbidden();
    $finance = $this->staffWithRole('finance', 'money@wurk.co.za');
    $this->actingAs($finance)->get($this->page)->assertForbidden();
});

it('resolves problems that have gone quiet for a week; one that returns is reopened', function () {
    wtFail('Once');
    $this->travel(8)->days();
    $this->artisan('watchtower:sweep')->expectsOutput('Resolved 1 quiet problem(s); removed 0 old one(s).');
    expect(Incident::query()->sole()->status)->toBe('resolved');
    wtFail('Once');
    expect(Incident::query()->sole())->status->toBe('new')->regressed_at->toBeNull();
});

it('masks personal data but keeps what a developer needs', function () {
    expect(Mask::text("select * from contacts where email = 'a@b.co' and id = '01jcz8x7k2m3n4p5q6r7s8t9v0'"))->toBe("select * from contacts where email = '?' and id = '?'")
        ->and(Mask::text('Undefined array key "name"'))->toBe('Undefined array key "name"')
        ->and(Mask::text('No query results for model [Contact] 01JCZ8X7K2M3N4P5Q6R7S8T9V0'))->toBe('No query results for model [Contact] [id]')
        ->and(Mask::text('Call +27 83 555 1234 or 0831234567'))->toBe('Call [number] or [number]')
        ->and(Mask::text('Value "Lerato Mokoena" is invalid'))->toBe('Value "?" is invalid')
        ->and(Mask::normalise('Order 12 not found'))->toBe(Mask::normalise('Order 345 not found'));
});
