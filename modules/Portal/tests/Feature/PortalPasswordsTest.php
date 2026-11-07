<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Mail\CustomerMessageMail;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($this->owner, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Thabo', 'owner'));
    $this->site = 'http://glow-salon.'.config('wurk.domains.root').'/account';
    $this->settings = $this->app('/glow-salon/settings/customer-accounts');
    $this->newCustomer = fn (string $email, ?string $phone = null) => ($this->in)(fn () => app(ContactBook::class)->remember('Lerato Dube', $phone, $email, 'order', (string) Str::ulid(), null, 'Order'));
    // The password, from the email the customer received (WURK never shows it to anyone else).
    $this->sent = function (string $email): ?string {
        $mail = collect(Mail::sent(CustomerMessageMail::class, fn ($m) => $m->hasTo($email)))->last();

        return $mail && preg_match('/[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}/', $mail->body, $m) ? $m[0] : null;
    };
});

it('sends nothing and offers no password sign-in until the business turns passwords on', function () {
    ($this->newCustomer)('lerato@example.com');
    expect(($this->sent)('lerato@example.com'))->toBeNull();
    $this->post("{$this->site}/password", ['identifier' => 'lerato@example.com', 'password' => 'anything'])->assertNotFound();
    $this->get($this->site)->assertInertia(fn (Assert $p) => $p->where('passwords', false));
});

it('emails a new customer a single-use default password, which leads straight to choosing their own', function () {
    $this->actingAs($this->owner)->put($this->settings, ['passwords' => true])->assertSessionHas('status', 'Customers can now sign in with a code or a password.');
    auth()->logout();
    ($this->newCustomer)('lerato@example.com');
    $password = ($this->sent)('lerato@example.com');
    expect($password)->not->toBeNull();
    ($this->in)(function () use ($password) {
        $row = DB::connection($this->ctx->connectionName())->table('portal_default_passwords')->where('tenant_id', $this->ctx->id())->sole();
        expect($row->hash)->not->toBe($password)->and(password_verify($password, $row->hash))->toBeTrue(); // only a hash is kept
        expect(json_encode(AuditEvent::query()->where('action', 'portal.password_sent')->get()->map(fn ($e) => $e->getAttributes())))->not->toContain($password);
    });

    $this->post("{$this->site}/password", ['identifier' => 'lerato@example.com', 'password' => 'wrong-wrong-wrong'])->assertSessionHasErrors(['password' => 'That email/number and password don’t match.']);
    $this->post("{$this->site}/password", ['identifier' => 'lerato@example.com', 'password' => $password])->assertRedirect();
    $this->get($this->site)->assertInertia(fn (Assert $p) => $p->component('Portal::SetPassword'));
    $this->post("{$this->site}/set-password", ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->post("{$this->site}/set-password", ['password' => 'Lerato-chooses-2027!', 'password_confirmation' => 'Lerato-chooses-2027!'])->assertSessionHas('status', 'Your password is set.');
    $this->get($this->site)->assertInertia(fn (Assert $p) => $p->component('Portal::Consent'));

    $this->post("{$this->site}/sign-out");
    $this->post("{$this->site}/password", ['identifier' => 'lerato@example.com', 'password' => $password])->assertSessionHasErrors('password'); // single-use
    $this->post("{$this->site}/password", ['identifier' => 'lerato@example.com', 'password' => 'Lerato-chooses-2027!'])->assertRedirect(); // her own now
});

it('expires default passwords after 7 days, never sends one to someone with their own WURK password, and sends by SMS on request', function () {
    $this->actingAs($this->owner)->put($this->settings, ['passwords' => true]);
    ($this->newCustomer)('lerato@example.com');
    $old = ($this->sent)('lerato@example.com');
    $this->travel(8)->days();
    auth()->logout();
    $this->post("{$this->site}/password", ['identifier' => 'lerato@example.com', 'password' => $old])->assertSessionHasErrors('password');

    $thandi = $this->person(['phone' => '+27820000077', 'email' => 'thandi@example.com']);
    app(Accounts::class)->setPassword($thandi->id, 'Thandi-own-pass-2027!');
    ($this->newCustomer)('thandi@example.com');
    expect(($this->sent)('thandi@example.com'))->toBeNull(); // she uses her own

    $sms = new class implements Messenger
    {
        public array $sent = [];

        public function sms(string $to, string $text, string $purpose): SendResult
        {
            $this->sent[] = [$to, $text];

            return SendResult::sent('test');
        }

        public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
        {
            return SendResult::sent('test');
        }

        public function channelActive(string $channel): bool
        {
            return true;
        }
    };
    app()->instance(Messenger::class, $sms);
    ($this->newCustomer)('sipho@example.com', '082 555 0101');
    $this->actingAs($this->owner)->post("{$this->settings}/send", ['customer' => '0825550101', 'channel' => 'sms'])->assertSessionHas('status', 'Sent Lerato Dube their portal access by SMS.');
    expect($sms->sent[0][0])->toBe('+27825550101')->and($sms->sent[0][1])->toMatch('/[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}/');
    $this->actingAs($this->owner)->post("{$this->settings}/send", ['customer' => 'thandi@example.com', 'channel' => 'email'])
        ->assertSessionHasErrors(['customer' => 'Lerato Dube already has a WURK password — they can sign in with it.']);
});

it('throttles repeated wrong default passwords, like WURK ID passwords', function () {
    $this->actingAs($this->owner)->put($this->settings, ['passwords' => true]);
    auth()->logout();
    ($this->newCustomer)('lerato@example.com');
    $right = ($this->sent)('lerato@example.com');
    for ($i = 0; $i < 5; $i++) {
        $this->post("{$this->site}/password", ['identifier' => 'lerato@example.com', 'password' => 'wrong-'.$i])->assertSessionHasErrors(['password' => 'That email/number and password don’t match.']);
    }
    $this->post("{$this->site}/password", ['identifier' => 'lerato@example.com', 'password' => $right])
        ->assertSessionHasErrors(['password' => 'Too many attempts. Try again later, or sign in with a code.']); // even the right one, until it cools down
});
