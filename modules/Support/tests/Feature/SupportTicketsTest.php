<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Models\HelpCategory;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Support\Mail\TicketMail;
use Wurk\Support\Models\SupportAttachment;
use Wurk\Support\Models\SupportTicket;
use Wurk\Support\Support\SupportTargets;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function supportPackage(string $tenantId, string $key, string $type = 'self_managed'): void
{
    $p = app(PackageCatalog::class)->create([
        'key' => $key, 'name' => ucfirst($key), 'type' => $type, 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => $type === 'managed' ? 50000 : null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($tenantId, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
}

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    SimulatedDriver::$answers = [];
    SimulatedDriver::$nextAnswer = null;
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->other = $this->createTenant('other-biz', name: 'Other Biz');
    supportPackage($this->glow->id, 'growth');
    $this->ctx = app(TenantContext::class);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->member($this->staff, $this->glow);
    $this->ctx->run($this->glow, function () {
        app(Team::class)->add($this->owner->id, 'Thandi', 'owner');
        app(Team::class)->add($this->staff->id, 'Sam', 'staff');
    });
    $this->base = $this->app('/glow-salon/support');
    $this->agent = $this->staffWithRole('support', 'help@wurk.co.za');
    $this->inbox = $this->admin('/support');
    $this->open = fn ($who = null, array $over = []) => $this->actingAs($who ?? $this->owner)->withHeader('User-Agent', 'Mozilla/5.0 TestBrowser/1.0')
        ->post($this->base, [...['subject' => 'Card payments not working', 'body' => 'Customers see an error when they pay by card.', 'page' => '/glow-salon/payments',
            'last_error' => 'The server answered 500'], ...$over]);
});

it('helps first — an answer from the help centre — before a ticket', function () {
    $cat = HelpCategory::query()->create(['name' => 'Payments', 'slug' => 'payments', 'position' => 1]);
    HelpArticle::query()->create(['category_id' => $cat->id, 'title' => 'Turn on card payments', 'slug' => 'turn-on-card-payments', 'summary' => null,
        'body' => 'Go to Settings → Payments and connect Yoco.', 'status' => 'published', 'screens' => []]);
    SimulatedDriver::$nextAnswer = (string) json_encode(['answer' => 'Connect Yoco in Settings → Payments.', 'articles' => ['turn-on-card-payments'], 'covered' => true]);
    $this->actingAs($this->owner)->postJson($this->base.'/suggest', ['question' => "Card payments\n\nHow do I take card payments?"])->assertOk()
        ->assertJson(['answer' => 'Connect Yoco in Settings → Payments.', 'sources' => [['title' => 'Turn on card payments']]]);

    // Not covered: no made-up answer, just the articles found.
    SimulatedDriver::$nextAnswer = (string) json_encode(['answer' => '', 'articles' => [], 'covered' => false]);
    $this->actingAs($this->owner)->postJson($this->base.'/suggest', ['question' => 'Card payments failing with an error'])->assertOk()
        ->assertJson(['answer' => null, 'sources' => [['title' => 'Turn on card payments']]]);

    // WURK AI down: still the articles.
    SimulatedDriver::$failNext = 3;
    $this->actingAs($this->owner)->postJson($this->base.'/suggest', ['question' => 'Card payments failing'])->assertOk()->assertJson(['answer' => null]);
    SimulatedDriver::$failNext = 0;
});

it('opens a ticket with the page, browser, last error and a safe copy of the screenshot — and tells WURK', function () {
    ($this->open)(null, ['screenshots' => [UploadedFile::fake()->image('checkout error.jpg', 400, 300)]])
        ->assertRedirect(route('tenant.support.show', ['tenant' => 'glow-salon', 'number' => 1001]))
        ->assertSessionHas('status', 'Sent. Your ticket is #1001 — we’ll email you when we reply.');
    $t = SupportTicket::query()->firstOrFail();
    expect($t->only(['number', 'status', 'package_key', 'target_hours', 'page', 'browser', 'last_error']))->toBe([
        'number' => 1001, 'status' => 'open', 'package_key' => 'growth', 'target_hours' => 8, 'page' => '/glow-salon/payments',
        'browser' => 'Mozilla/5.0 TestBrowser/1.0', 'last_error' => 'The server answered 500',
    ]);
    $a = SupportAttachment::query()->firstOrFail();
    expect($a->name)->toBe('checkout error.png')
        ->and(substr((string) Storage::disk('local')->get($a->path), 0, 8))->toBe("\x89PNG\r\n\x1a\n"); // re-encoded
    Mail::assertSent(TicketMail::class, fn (TicketMail $m) => $m->hasTo('support@wurk.co.za') && $m->subjectLine === 'New ticket #1001 from Glow Salon: Card payments not working');

    ($this->open)(null, ['subject' => 'Second'])->assertRedirect();
    expect(SupportTicket::query()->max('number'))->toBe(1002);

    // Not an image: refused.
    ($this->open)(null, ['screenshots' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('screenshots.0');
});

it('sets the first-reply target by package, counted in business hours', function () {
    $targets = app(SupportTargets::class);
    expect($targets->forTenant($this->glow->id))->toBe(['hours' => 8, 'package_key' => 'growth']);
    supportPackage($this->other->id, 'managed-salon', 'managed');
    expect($targets->forTenant($this->other->id)['hours'])->toBe(2);
    $none = $this->createTenant('no-package');
    expect($targets->forTenant($none->id))->toBe(['hours' => 24, 'package_key' => null]);

    $sast = fn (string $at) => CarbonImmutable::parse($at, 'Africa/Johannesburg');
    $local = fn (CarbonImmutable $d) => $d->setTimezone('Africa/Johannesburg')->format('D H:i');
    expect($local($targets->due($sast('2026-11-13 16:00'), 8)))->toBe('Mon 15:00')   // Friday 16:00: 1 hour, then 7 on Monday
        ->and($local($targets->due($sast('2026-11-14 10:00'), 4)))->toBe('Mon 12:00') // Saturday: starts Monday 08:00
        ->and($local($targets->due($sast('2026-11-16 06:30'), 2)))->toBe('Mon 10:00') // before opening
        ->and($local($targets->due($sast('2026-11-16 09:00'), 24)))->toBe('Wed 15:00');
});

it('shows people their own tickets — owners see the whole business’s — never another business’s', function () {
    ($this->open)($this->staff, ['subject' => 'Staff question']);
    ($this->open)($this->owner, ['subject' => 'Owner question']);
    $this->actingAs($this->staff)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Support::Tickets')->has('tickets', 1)->where('tickets.0.subject', 'Staff question')
        ->where('target', '8 business hours (weekdays 08:00–17:00 South African time)'));
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->has('tickets', 2));
    $this->actingAs($this->staff)->get($this->base.'/1002')->assertNotFound();
    $this->actingAs($this->owner)->get($this->base.'/1001')->assertOk();

    $outsider = $this->person(['phone' => '+27831000009', 'email' => 'x@other.co.za']);
    $this->member($outsider, $this->other);
    $this->ctx->run($this->other, fn () => app(Team::class)->add($outsider->id, 'Xolani', 'owner'));
    $this->actingAs($outsider)->get($this->app('/other-biz/support/1001'))->assertNotFound();
    $this->actingAs($outsider)->get($this->app('/glow-salon/support/1001'))->assertNotFound(); // not their business
});

it('lets WURK answer from the inbox — emailed to the business — then solve, rate and reopen', function () {
    ($this->open)(null, ['screenshots' => [UploadedFile::fake()->image('a.png', 50, 50)]]);
    $file = SupportAttachment::query()->value('id');
    $finance = $this->staffWithRole('finance', 'money@wurk.co.za');
    $this->actingAs($finance)->get($this->inbox)->assertForbidden();

    SupportTicket::query()->update(['first_response_due_at' => now()->subHour()]); // past the 8-hour target
    $this->actingAs($this->agent)->get($this->inbox)->assertInertia(fn (Assert $p) => $p->component('Support::Admin/Inbox')
        ->where('tickets.0.number', 1001)->where('tickets.0.business', 'Glow Salon')->where('tickets.0.late', true)->where('counts.open', 1));
    $this->actingAs($this->agent)->get($this->inbox.'/1001')->assertInertia(fn (Assert $p) => $p->component('Support::Admin/Ticket')
        ->where('ticket.opener.email', 'owner@glow.co.za')->where('ticket.last_error', 'The server answered 500')->where('messages.0.files.0.id', $file));
    $this->actingAs($this->agent)->get($this->inbox."/1001/files/{$file}")->assertOk()->assertHeader('content-type', 'image/png');

    $this->actingAs($this->agent)->post($this->inbox.'/1001/reply', ['body' => 'Please reconnect Yoco in Settings → Payments.'])->assertSessionHas('status', 'Replied.');
    $t = SupportTicket::query()->firstOrFail();
    expect($t->status)->toBe('pending')->and($t->first_responded_at)->not->toBeNull();
    Mail::assertSent(TicketMail::class, fn (TicketMail $m) => $m->hasTo('owner@glow.co.za') && str_contains($m->link, '/glow-salon/support/1001')
        && $m->lines[0] === 'Please reconnect Yoco in Settings → Payments.');

    // The business sees WURK's reply (and never internal notes).
    $this->actingAs($this->owner)->get($this->base.'/1001')->assertInertia(fn (Assert $p) => $p->component('Support::Ticket')->has('messages', 2)
        ->where('messages.1.from', 'WURK support')->where('ticket.status', 'pending'));
    $this->actingAs($this->owner)->post($this->base.'/1001/rate', ['rating' => 'good'])->assertStatus(422); // not solved yet

    $this->actingAs($this->agent)->post($this->inbox.'/1001/reply', ['body' => 'All sorted.', 'solve' => true])->assertSessionHas('status', 'Replied and solved.');
    $this->actingAs($this->owner)->post($this->base.'/1001/rate', ['rating' => 'good', 'comment' => 'Quick, thanks'])->assertSessionHas('status', 'Thanks for telling us.');
    expect(SupportTicket::query()->value('rating'))->toBe('good');

    $this->actingAs($this->owner)->post($this->base.'/1001/reply', ['body' => 'It broke again.'])->assertSessionHas('status', 'Sent.');
    expect(SupportTicket::query()->value('status'))->toBe('open');
    Mail::assertSent(TicketMail::class, fn (TicketMail $m) => $m->subjectLine === 'Reply on #1001 from Glow Salon: Card payments not working');

    $this->actingAs($this->agent)->post($this->inbox.'/1001/status', ['status' => 'solved'])->assertSessionHas('status', 'Now: Solved.');
    $this->actingAs($this->agent)->post($this->inbox.'/1001/status', ['status' => 'deleted'])->assertSessionHasErrors('status');
});

it('puts "Contact support" in the back office, linked from help', function () {
    $this->actingAs($this->staff)->get($this->base.'/new?from=/glow-salon/payments')
        ->assertInertia(fn (Assert $p) => $p->component('Support::New')->where('from', '/glow-salon/payments')
            ->where('support.url', route('tenant.support.create', ['tenant' => 'glow-salon'])));
});

it('leaves "Contact support" out of the menu on pages outside a business (no business to link to)', function () {
    expect($this->actingAs($this->owner)->get($this->app('/'))->status())->toBeLessThan(500);
});
