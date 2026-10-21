<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Audit\Contracts\Auditable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Tenancy\Contracts\BelongsToTenant;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

final class AuditedThing extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'audited_things';

    protected $fillable = ['name', 'secret_note', 'api_key'];

    protected $hidden = ['secret_note'];
}

beforeEach(function () {
    $this->useFakeOtpChannel();
    [$one, $two] = $this->createShards(2);
    $this->a = $this->createTenant('tenant-a', $one);
    $this->b = $this->createTenant('tenant-b', $two);
    $this->context = app(TenantContext::class);
});

function platformActions(): array
{
    return PlatformAuditEvent::query()->orderBy('seq')->pluck('action')->all();
}

it('records business creation and status changes on the platform chain', function () {
    $this->a->update(['status' => 'suspended']);

    expect(platformActions())->toContain('tenant.created', 'tenant.status_changed')
        ->and(PlatformAuditEvent::query()->where('action', 'tenant.status_changed')->value('changes'))
        ->toMatchArray(['from' => 'active', 'to' => 'suspended', 'handle' => 'tenant-a']);
});

it('records sign-ins with who, how and from where, and failures without an identity', function () {
    $person = $this->person(['password' => 'a very good passphrase']);

    $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'wrong'], ['REMOTE_ADDR' => '41.13.0.7']);
    $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase'], ['REMOTE_ADDR' => '41.13.0.7']);

    $failed = PlatformAuditEvent::query()->where('action', 'identity.sign_in_failed')->firstOrFail();
    $signedIn = PlatformAuditEvent::query()->where('action', 'identity.signed_in')->firstOrFail();

    expect($failed->actor_identity_id)->toBeNull()
        ->and($failed->changes)->toMatchArray(['reason' => 'bad_password', 'surface' => 'app'])
        ->and($signedIn->actor_identity_id)->toBe($person->id)
        ->and($signedIn->actor_name)->toBe('Thandi Mokoena')
        ->and($signedIn->ip)->toBe('41.13.0.7')
        ->and($signedIn->getAttribute('request_id'))->not->toBeNull();
});

it('never writes one-time codes or passwords into the trail', function () {
    $this->person(['password' => 'a very good passphrase']);
    $this->post($this->app('/sign-in/code'), ['identifier' => 'thandi@glow.co.za']);
    $code = FakeOtpChannel::$codes['thandi@glow.co.za'];
    $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase']);

    $everything = json_encode(DB::table('audit_events')->get());
    expect($everything)->not->toContain($code)->not->toContain('a very good passphrase');
});

it('records team changes on the business chain only', function () {
    $person = $this->person();
    $this->context->run($this->a, fn () => app(Team::class)->add($person->id, 'Thandi', SystemRoles::OWNER));

    expect($this->context->run($this->a, fn () => AuditEvent::query()->pluck('action')->all()))->toContain('role.assigned')
        ->and($this->context->run($this->b, fn () => AuditEvent::query()->count()))->toBe(0)
        ->and(platformActions())->not->toContain('role.assigned');
});

it('audits model changes automatically and redacts secrets', function () {
    $this->context->run($this->a, function () {
        Schema::connection($this->context->connectionName())->create('audited_things', function ($t) {
            $t->ulid('id')->primary();
            $t->ulid('tenant_id');
            $t->string('name');
            $t->string('secret_note')->nullable();
            $t->string('api_key')->nullable();
            $t->timestamps();
        });
        $thing = AuditedThing::query()->create(['name' => 'Gift voucher', 'secret_note' => 'hush', 'api_key' => 'sk_live_123']);
        $thing->update(['name' => 'Spa voucher']);
        $thing->delete();
    });

    $events = $this->context->run($this->a, fn () => AuditEvent::query()->where('action', 'like', 'audited_thing.%')->orderBy('seq')->get());

    expect($events->pluck('action')->all())->toBe(['audited_thing.created', 'audited_thing.updated', 'audited_thing.deleted'])
        ->and($events[0]->changes['after'])->toMatchArray(['secret_note' => '[redacted]', 'api_key' => '[redacted]', 'name' => 'Gift voucher'])
        ->and($events[1]->changes)->toMatchArray(['before' => ['name' => 'Gift voucher'], 'after' => ['name' => 'Spa voucher']]);
});

it('numbers events gaplessly and links each to the previous one', function () {
    foreach (range(1, 5) as $i) {
        app(AuditLog::class)->record("test.event_{$i}", options: ['platform' => true]);
    }

    $rows = PlatformAuditEvent::query()->orderBy('seq')->get();
    expect($rows->pluck('seq')->all())->toBe(range(1, $rows->count()));
    foreach ($rows->skip(1)->values() as $i => $row) {
        expect($row->prev_hash)->toBe($rows[$i]->hash);
    }
});

it('refuses to change or delete events through the application', function () {
    app(AuditLog::class)->record('test.event', options: ['platform' => true]);
    $event = PlatformAuditEvent::query()->latest('seq')->firstOrFail();

    expect(fn () => $event->update(['action' => 'forged']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

it('verifies intact chains', function () {
    $this->context->run($this->a, fn () => app(AuditLog::class)->record('test.business_event'));

    $this->artisan('audit:verify')->expectsOutputToContain('audit chains are intact')->assertSuccessful();
});

it('detects an edited event', function () {
    foreach (range(1, 3) as $_) {
        app(AuditLog::class)->record('test.event', options: ['platform' => true]);
    }
    $target = PlatformAuditEvent::query()->orderBy('seq')->skip(1)->firstOrFail();
    DB::table('audit_events')->where('id', $target->id)->update(['actor_name' => 'Someone else']);

    $this->artisan('audit:verify')->expectsOutputToContain("Event {$target->seq} was altered")->assertFailed();
});

it('detects a deleted event in the middle and at the end', function (string $where) {
    foreach (range(1, 4) as $_) {
        $this->context->run($this->b, fn () => app(AuditLog::class)->record('test.event'));
    }
    $connection = $this->context->run($this->b, fn () => $this->context->connectionName());
    $seq = $where === 'middle' ? 2 : DB::connection($connection)->table('audit_events')->max('seq');
    DB::connection($connection)->table('audit_events')->where('scope', $this->b->id)->where('seq', $seq)->delete();

    $this->artisan('audit:verify')
        ->expectsOutputToContain($where === 'middle' ? 'is missing' : 'newest events were removed')
        ->assertFailed();
})->with(['middle', 'end']);
