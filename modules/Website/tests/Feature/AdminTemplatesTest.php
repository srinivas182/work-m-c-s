<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\TemplateAsset;
use Wurk\Website\Support\Templates;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    Storage::fake('public');
    $this->artisan('website:seed-categories');
    $this->staff = $this->staffWithRole('super-admin', 'design@wurk.co.za');
    $this->template = app(Templates::class)->create(SiteCategory::query()->where('key', 'salon-beauty')->firstOrFail(), 'salon-hair', 'Hair & braids salon', null, null, null);
    $this->base = $this->admin('/templates/salon-hair');
});

it('is only for staff allowed to manage templates', function () {
    $support = $this->staffWithRole('support', 'support@wurk.co.za');
    $this->actingAs($support)->get($this->admin('/templates'))->assertForbidden();
    $this->actingAs($support)->postJson($this->base.'/publish')->assertForbidden();
    $this->actingAs($this->staff)->get($this->admin('/templates'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Website::Admin/Templates'));
});

it('creates templates blank or as a copy, and keeps categories editable', function () {
    $this->actingAs($this->staff)->post($this->admin('/templates'), ['category' => 'salon-beauty', 'name' => 'Nails, lashes & barbers', 'copy_from' => 'salon-hair'])->assertSessionHasNoErrors();
    expect(SiteTemplate::query()->where('name', 'Nails, lashes & barbers')->value('draft'))->toBe($this->template->draft);

    $this->actingAs($this->staff)->put($this->admin('/templates/categories/salon-beauty'), ['name' => 'Salons & beauty', 'description' => null, 'active' => false])->assertSessionHasNoErrors();
    expect(SiteCategory::query()->where('key', 'salon-beauty')->first()?->only(['name', 'active']))->toBe(['name' => 'Salons & beauty', 'active' => false]);
});

it('edits pages in the visual editor, refusing stale saves', function () {
    $this->actingAs($this->staff)->get($this->base.'/pages/home/edit')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Website::Editor')->where('revision', 1)->where('urls.draft', '/templates/salon-hair/pages/home/draft')->where('urls.photos', '/templates/salon-hair/photos'));

    $content = ['schema' => 1, 'blocks' => [['id' => 'h', 'type' => 'Text', 'version' => 1, 'props' => ['heading' => 'Braids that last']]]];
    $this->actingAs($this->staff)->putJson($this->base.'/pages/home/draft', ['revision' => 1, 'content' => $content])->assertOk()->assertJson(['revision' => 2]);
    $this->actingAs($this->staff)->putJson($this->base.'/pages/home/draft', ['revision' => 1, 'content' => $content])->assertStatus(409);
    $this->actingAs($this->staff)->putJson($this->base.'/pages/home/draft', ['revision' => 2, 'content' => ['schema' => 9]])->assertUnprocessable();
});

it('changes nothing when a page-list save is refused', function () {
    $draft = json_decode($this->template->draft, true);
    $this->actingAs($this->staff)->put($this->base, ['name' => 'Renamed', 'revision' => 99, 'pages' => $draft['pages'], 'menus' => $draft['menus'], 'branding' => $draft['branding']])
        ->assertSessionHasErrors('revision');
    expect($this->template->fresh()->name)->toBe('Hair & braids salon');
});

it('publishes from the editor, lists versions and restores one into the draft', function () {
    $this->actingAs($this->staff)->postJson($this->base.'/publish', ['note' => 'Launch'])->assertOk()->assertJson(['version' => 1]);
    $this->actingAs($this->staff)->putJson($this->base.'/pages/home/draft', ['revision' => 1, 'content' => ['schema' => 1, 'blocks' => []]])->assertOk();
    $versions = $this->actingAs($this->staff)->getJson($this->base.'/versions')->assertOk()->json();
    expect($versions[0]['number'])->toBe(1);

    $this->actingAs($this->staff)->postJson($this->base.'/versions/'.$versions[0]['id'].'/restore')->assertOk();
    expect(json_decode($this->template->fresh()->draft, true)['pages'][0]['content'])->toBe(json_decode(app(Templates::class)->published('salon-hair')['definition'] ? json_encode(app(Templates::class)->published('salon-hair')['definition']) : '{}', true)['pages'][0]['content']);
});

it('stores template photos apart from businesses’ photos and lists them without reading the images', function () {
    $photo = UploadedFile::fake()->image('salon.jpg', 2000, 1200);
    $this->actingAs($this->staff)->post($this->base.'/photos', ['photo' => $photo])->assertCreated()->assertJson(['width' => 1600, 'height' => 960]);

    $asset = TemplateAsset::query()->firstOrFail();
    expect(Storage::disk('public')->allFiles())->each->toStartWith('templates/salon-hair/')
        ->and($asset->only(['width', 'height']))->toBe(['width' => 2000, 'height' => 1200]);
    $this->actingAs($this->staff)->getJson($this->base.'/photos')->assertOk()->assertJsonCount(1, 'photos')->assertJson(['limit_mb' => 0]);
});
