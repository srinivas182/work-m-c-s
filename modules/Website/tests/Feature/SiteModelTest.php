<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\AccessManager;
use Wurk\Access\Actions\CustomRoles;
use Wurk\Access\Actions\Team;
use Wurk\Access\Models\Member;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Models\EntitlementOverride;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\ContentDocument;
use Wurk\Website\Support\Pages;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function doc(string ...$texts): array
{
    return ['schema' => 1, 'blocks' => array_map(fn ($t, $i) => ['id' => "b{$i}", 'type' => 'Text', 'version' => 1, 'props' => ['text' => $t]], $texts, array_keys($texts))];
}

beforeEach(function () {
    [$one, $two] = $this->createShards(2);
    $this->glow = $this->createTenant('glow-salon', $one);
    $this->other = $this->createTenant('other-biz', $two);
    $this->context = app(TenantContext::class);
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    foreach ([$this->glow, $this->other] as $t) {
        app(Catalog::class)->subscribe($t->id, $package->id, 'monthly', allowTrial: true);
    }
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->context->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
});

function inGlowSite($test, callable $fn): mixed
{
    return $test->context->run($test->glow, $fn);
}

it('gives every business a site with a home page, isolated from other businesses', function () {
    inGlowSite($this, fn () => app(Pages::class)->create('Services', null, null));
    $this->context->run($this->other, fn () => app(Pages::class)->ensureSite());

    expect(inGlowSite($this, fn () => Page::query()->orderBy('position')->pluck('slug')->all()))->toBe(['home', 'services'])
        ->and($this->context->run($this->other, fn () => Page::query()->pluck('slug')->all()))->toBe(['home']);

    $glowPage = inGlowSite($this, fn () => Page::query()->where('slug', 'services')->value('id'));
    $this->actingAs($this->owner)->get($this->app("/glow-salon/website/pages/{$glowPage}/draft"))->assertOk();
    $outsider = $this->person(['email' => 'out@other.co.za', 'phone' => null]);
    $this->member($outsider, $this->other);
    $this->context->run($this->other, fn () => app(Team::class)->add($outsider->id, 'O', 'owner'));
    $this->actingAs($outsider)->get($this->app("/other-biz/website/pages/{$glowPage}/draft"))->assertNotFound(); // another business's page id
});

it('enforces page addresses and a single home page', function () {
    inGlowSite($this, function () {
        $pages = app(Pages::class);
        $about = $pages->create('About Us', null, null);
        expect($about->slug)->toBe('about-us')
            ->and(fn () => $pages->create('About us', null, null))->toThrow(ValidationException::class)
            ->and(fn () => $pages->create('Sitemap', null, null))->toThrow(ValidationException::class);

        $pages->setHome($about);
        expect(Page::query()->where('is_home', true)->pluck('slug')->all())->toBe(['about-us'])
            ->and(fn () => $pages->delete($about->fresh()))->toThrow(ValidationException::class);
    });
});

it('keeps deleted pages for 30 days and restores them, renaming a reused address', function () {
    inGlowSite($this, function () {
        $pages = app(Pages::class);
        $old = $pages->create('Prices', null, null);
        $pages->delete($old);
        $pages->create('Prices', null, null); // address reused meanwhile
        expect($pages->restoreDeleted($old->id)->slug)->toBe('prices-2');

        $gone = $pages->create('Old offer', null, null);
        $pages->delete($gone);
        $this->travel(31)->days();
        expect(fn () => $pages->restoreDeleted($gone->id))->toThrow(ModelNotFoundException::class);
    });
});

it('never lets one editor silently overwrite another', function () {
    $page = inGlowSite($this, fn () => app(Pages::class)->create('Services', null, null));
    $url = $this->app("/glow-salon/website/pages/{$page->id}/draft");

    $this->actingAs($this->owner)->putJson($url, ['revision' => 1, 'content' => doc('First editor')])->assertOk()->assertJson(['revision' => 2]);
    $this->actingAs($this->owner)->putJson($url, ['revision' => 1, 'content' => doc('Second editor, stale')])->assertStatus(409)->assertJson(['revision' => 2]);

    expect(inGlowSite($this, fn () => json_decode(Page::query()->find($page->id)->draft, true)['blocks'][0]['props']['text']))->toBe('First editor');
});

it('publishes immutable versions and restores any of them exactly', function () {
    $page = inGlowSite($this, fn () => app(Pages::class)->create('Services', null, null));
    inGlowSite($this, function () use ($page) {
        $pages = app(Pages::class);
        $revision = 1;
        foreach (range(1, 5) as $n) {
            $revision = $pages->saveDraft($page, doc("Version {$n}", 'Same footer'), $revision, null);
            $pages->publish($page, "Release {$n}", null);
        }

        $v3 = PageVersion::query()->where('page_id', $page->id)->where('number', 3)->firstOrFail();
        $pages->restoreVersion($page, $v3, null);
        expect(Page::query()->find($page->id)->draft)->toBe($v3->content)
            ->and(Page::query()->find($page->id)->published_version_id)->toBe(PageVersion::query()->where('number', 5)->value('id')) // restore doesn't publish
            ->and(fn () => $v3->update(['content' => '{}']))->toThrow(LogicException::class);
    });
});

it('accepts only well-formed content documents', function () {
    expect(ContentDocument::validate(doc('ok'))['blocks'][0]['type'])->toBe('Text');
    // Puck's ids are "<Type>-<uuid>", longer than 40 characters.
    $puckId = 'ServicesPrices-0b7f0a7e-8a1f-4d8e-9a3b-2f1c4e5d6a7b';
    expect(ContentDocument::validate(['schema' => 1, 'blocks' => [['id' => $puckId, 'type' => 'ServicesPrices', 'version' => 1, 'props' => []]]])['blocks'][0]['id'])->toBe($puckId);
    foreach ([
        ['schema' => 2, 'blocks' => []],
        ['schema' => 1, 'blocks' => [['id' => 'a', 'type' => 'Text', 'version' => 1, 'props' => []], ['id' => 'a', 'type' => 'Text', 'version' => 1, 'props' => []]]],
        ['schema' => 1, 'blocks' => [['id' => 'a', 'type' => '<script>', 'version' => 1, 'props' => []]]],
        ['schema' => 1, 'blocks' => [['id' => 'a', 'type' => 'Text', 'props' => []]]],
        ['schema' => 1, 'blocks' => array_fill(0, 81, ['id' => 'x', 'type' => 'Text', 'version' => 1, 'props' => []])],
        ['schema' => 1, 'blocks' => [['id' => 'a', 'type' => 'Text', 'version' => 1, 'props' => ['text' => str_repeat('x', 600 * 1024)]]]],
    ] as $bad) {
        expect(fn () => ContentDocument::validate($bad))->toThrow(ValidationException::class);
    }
});

it('validates branding inputs and records changes', function () {
    $url = $this->app('/glow-salon/website/branding');
    $this->actingAs($this->owner)->putJson($url, ['primary' => 'purple', 'font_pairing' => 'comic', 'corners' => 'soft'])->assertJsonValidationErrors(['primary', 'font_pairing']);
    $this->actingAs($this->owner)->putJson($url, ['primary' => '#0f766e', 'secondary' => '#f59e0b', 'font_pairing' => 'classic', 'corners' => 'round'])
        ->assertOk()->assertJsonPath('branding.primary', '#0F766E');

    expect(inGlowSite($this, fn () => AuditEvent::query()->where('action', 'website.branding_changed')->exists()))->toBeTrue();
});

it('needs the website module, edit permission to edit, and publish permission to publish', function () {
    $desk = $this->person(['email' => 'desk@glow.co.za', 'phone' => null]);
    $this->member($desk, $this->glow);
    $this->context->run($this->glow, fn () => app(Team::class)->add($desk->id, 'Desk', 'staff'));
    $this->actingAs($desk)->get($this->app('/glow-salon/website/pages'))->assertNotFound();

    $page = inGlowSite($this, fn () => app(Pages::class)->create('Services', null, null));
    $editor = inGlowSite($this, fn () => app(CustomRoles::class)->createBusinessRole('Web editor', ['website.edit']));
    $this->context->run($this->glow, fn () => app(Team::class)->assign(Member::query()->where('identity_id', $desk->id)->firstOrFail(), $editor->key()));
    app(AccessManager::class)->flush();
    $this->actingAs($desk)->get($this->app('/glow-salon/website/pages'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('canPublish', false));
    $this->actingAs($desk)->postJson($this->app("/glow-salon/website/pages/{$page->id}/publish"))->assertNotFound();
    $this->actingAs($this->owner)->postJson($this->app("/glow-salon/website/pages/{$page->id}/publish"), ['note' => 'Launch'])->assertOk()->assertJson(['version' => 1]);

    EntitlementOverride::query()->create(['tenant_id' => $this->glow->id, 'module_key' => 'website', 'granted' => false, 'reason' => 'test']);
    app(Entitlements::class)->flush();
    $this->actingAs($this->owner)->get($this->app('/glow-salon/website/pages'))->assertStatus(403)->assertInertia(fn (Assert $p) => $p->component('Plans::NotIncluded'));
});
