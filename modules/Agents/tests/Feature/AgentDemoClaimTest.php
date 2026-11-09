<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Agents\Support\AgentDemos;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Website\Contracts\SiteTemplates;
use Wurk\Website\Models\Page;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->useFakeOtpChannel();
    Mail::fake();
    $this->createShards(1);
    $this->artisan('website:seed-categories');
    $this->artisan('website:install-templates');
    $this->package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    $this->dineo = app(AgentRoster::class)->create('dineo@example.com', 'Dineo', 'Agent', null, null);
    $cat = app(SiteTemplates::class)->catalogue()[0];
    $this->demoId = app(AgentDemos::class)->create($this->dineo, 'Thandi’s Braids', $cat['key'], $cat['templates'][0]['key']);
    $this->demo = Tenant::query()->findOrFail($this->demoId);
    $this->pages = app(TenantContext::class)->run($this->demo, fn () => Page::query()->count());
    $this->app_ = fn (string $p) => 'http://'.config('wurk.domains.app').$p;
    $this->claimUrl = app(AgentDemos::class)->claimLink($this->dineo);
});

it('turns the demo into the prospect’s live business — content kept, owner changed, agent credited', function () {
    $site = $this->get('http://'.$this->demo->handle.'.'.config('wurk.domains.root').'/')->getContent();
    expect($site)->toContain('Make it yours')->toContain('/signup/claim/');

    $this->get($this->claimUrl)->assertRedirect('/signup');
    $this->get(($this->app_)('/signup'))->assertInertia(fn (Assert $p) => $p->where('claim.business', 'Thandi’s Braids')
        ->where('data.business_name', 'Thandi’s Braids')->where('data.handle', 'thandis-braids')->where('steps', fn ($s) => ! in_array('template', (array) $s, true)));
    $this->post(($this->app_)('/signup/details'), ['business_name' => 'Thandi’s Braids', 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'contact' => 'thandi@example.com', 'country' => 'ZA'])->assertSessionHasNoErrors();
    $this->post(($this->app_)('/signup/verify'), ['code' => FakeOtpChannel::$codes['thandi@example.com']])->assertSessionHasNoErrors();
    $this->post(($this->app_)('/signup/package'), ['package_id' => $this->package->id, 'period' => 'monthly'])->assertSessionHasNoErrors();
    $this->post(($this->app_)('/signup/address'), ['handle' => 'thandis-braids'])->assertSessionHasNoErrors();
    $this->post(($this->app_)('/signup/complete'))->assertRedirect();

    $t = Tenant::query()->findOrFail($this->demoId); // the same business, not a new one
    expect($t->handle)->toBe('thandis-braids')->and($t->isDemo())->toBeFalse()->and(Tenant::query()->count())->toBe(1)
        ->and(app(TenantContext::class)->run($t, fn () => Page::query()->count()))->toBe($this->pages);
    $this->get('http://thandis-braids.'.config('wurk.domains.root').'/')->assertOk()->assertDontSee('Make it yours');
    $this->get('http://'.$this->demo->handle.'.'.config('wurk.domains.root').'/')->assertNotFound(); // the demo address is gone
    $thandi = (string) DB::table('identities')->where('email', 'thandi@example.com')->value('id');
    $roster = collect(app(TenantContext::class)->run($t, fn () => app(TeamDirectory::class)->roster()));
    expect($roster->firstWhere('identity_id', $thandi)['role'])->toBe('owner')
        ->and($roster->firstWhere('identity_id', $this->dineo->identity_id)['active'])->toBeFalse()           // the agent's access ended
        ->and(DB::table('agent_businesses')->where('tenant_id', $t->id)->first())->toMatchObject(['agent_id' => $this->dineo->id, 'source' => 'demo'])
        ->and(app(AgentDemos::class)->active($this->dineo))->toBeNull();                                      // free to make another
});

it('lets the prospect keep the demo’s own address, and turns away expired or forged links', function () {
    $this->get($this->claimUrl);
    $this->post(($this->app_)('/signup/details'), ['business_name' => 'Thandi’s Braids', 'first_name' => 'Thandi', 'last_name' => 'M', 'contact' => 'thandi@example.com', 'country' => 'ZA']);
    $this->post(($this->app_)('/signup/verify'), ['code' => FakeOtpChannel::$codes['thandi@example.com']]);
    $this->post(($this->app_)('/signup/package'), ['package_id' => $this->package->id, 'period' => 'monthly']);
    $this->post(($this->app_)('/signup/address'), ['handle' => $this->demo->handle])->assertSessionHasNoErrors(); // its own address is fine

    $this->flushSession();
    $this->get($this->claimUrl.'0')->assertRedirect('/signup')->assertSessionHas('status', 'That demo link has expired. You can still sign up below.'); // tampered
    $this->travel(15)->days();
    $this->get($this->claimUrl)->assertRedirect('/signup')->assertSessionHas('status');                                                          // expired
});
