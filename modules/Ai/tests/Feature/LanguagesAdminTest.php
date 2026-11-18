<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Languages\DraftTranslationsJob;
use Wurk\Ai\Languages\TranslationDrafts;
use Wurk\Platform\I18n\Locales;
use Wurk\Platform\I18n\TranslationStore;

/*
 * Sprint 24.2: WURK AI drafts each language (placeholders must survive), people review in context, reviews win over
 * drafts and take effect at once (PHP and the browser), and a language is "preview" until it's 95% reviewed.
 */

uses(InteractsWithIdentity::class);

beforeEach(function () {
    $this->source = File::exists(lang_path('_source.json')) ? File::get(lang_path('_source.json')) : null;
    File::put(lang_path('_source.json'), json_encode(['Language saved.', 'Hi :name', 'Save']));
    SimulatedDriver::$nextAnswer = null;
    $this->hq = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $this->page = $this->admin('/settings/languages');
});

afterEach(fn () => $this->source === null ? File::delete(lang_path('_source.json')) : File::put(lang_path('_source.json'), $this->source));

it('drafts with WURK AI and its glossary, keeping placeholders, never over a review', function () {
    DB::table('translation_glossary')->insert(['id' => '01J0000000000000000000GLOS', 'locale' => 'zu', 'term' => 'Save', 'translation' => 'Gcina', 'created_at' => now(), 'updated_at' => now()]);
    TranslationStore::save('zu', 'Save', 'Londoloza', 'reviewed', $this->hq->id);
    SimulatedDriver::$nextAnswer = json_encode(['Language saved.' => 'Ulimi lugciniwe.', 'Hi :name' => 'Sawubona']); // placeholder lost: dropped
    expect(app(TranslationDrafts::class)->draft('zu'))->toBe(1);
    expect(Locales::messages('zu'))->toBe(['Language saved.' => 'Ulimi lugciniwe.', 'Save' => 'Londoloza'])
        ->and(__('Language saved.', [], 'zu'))->toBe('Ulimi lugciniwe.'); // PHP sees it at once

    SimulatedDriver::$nextAnswer = json_encode(['Hi :name' => 'Sawubona :name', 'Save' => 'Gcina']);
    app(TranslationDrafts::class)->draft('zu');
    expect(Locales::messages('zu')['Save'])->toBe('Londoloza')->and(Locales::messages('zu')['Hi :name'])->toBe('Sawubona :name');
});

it('lets reviewers correct texts in context, and shows each language’s progress', function () {
    TranslationStore::save('zu', 'Language saved.', 'Ulimi lugciniwe.', 'draft', null);
    $this->actingAs($this->hq)->get($this->page)->assertInertia(fn (Assert $p) => $p->component('Ai::Admin/Languages')
        ->where('languages.0.code', 'zu')->where('languages.0.translated', 1)->where('languages.0.reviewed', 0)->where('languages.0.preview', true));
    $this->actingAs($this->hq)->get($this->page.'/zu')->assertInertia(fn (Assert $p) => $p->where('items.0', ['source' => 'Language saved.', 'text' => 'Ulimi lugciniwe.', 'status' => 'draft']));

    $before = Locales::version('zu');
    $this->travel(1)->seconds();
    $this->actingAs($this->hq)->put($this->page.'/zu', ['source' => 'Hi :name', 'text' => 'Sawubona'])->assertSessionHas('warning');
    $this->actingAs($this->hq)->put($this->page.'/zu', ['source' => 'Not one of ours', 'text' => 'x'])->assertSessionHasErrors('source');
    foreach (['Language saved.' => 'Ulimi lulondoloziwe.', 'Hi :name' => 'Sawubona :name', 'Save' => 'Londoloza'] as $source => $text) {
        $this->actingAs($this->hq)->put($this->page.'/zu', ['source' => $source, 'text' => $text])->assertSessionHas('status', 'Saved.');
    }
    expect(Locales::version('zu'))->not->toBe($before) // the browser fetches the new translations
        ->and(TranslationStore::progress()['zu'])->toMatchArray(['translated' => 3, 'reviewed' => 3, 'preview' => false]);
    expect(collect(Locales::offered())->firstWhere('code', 'zu'))->toBe(['code' => 'zu', 'name' => 'isiZulu', 'preview' => false]);

    $this->actingAs($this->hq)->put($this->page.'/xh/offered', ['enabled' => false])->assertSessionHas('status');
    Cache::flush();
    expect(collect(Locales::offered())->pluck('code')->all())->not->toContain('xh');
});

it('drafts in the background, and is only for people allowed to', function () {
    Queue::fake();
    $this->actingAs($this->hq)->post($this->page.'/fr/draft')->assertSessionHas('status');
    Queue::assertPushed(DraftTranslationsJob::class, fn ($j) => $j->locale === 'fr');

    $support = $this->staffWithRole('support', 'support@wurk.co.za');
    $this->actingAs($support)->get($this->page)->assertForbidden();
});
