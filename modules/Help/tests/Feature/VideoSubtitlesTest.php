<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Languages\CaptionDrafts;
use Wurk\Ai\Languages\DraftCaptionsJob;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Models\HelpVideoCaption;

/*
 * Sprint 25.6: subtitles for the 30-second help videos. English is written with the video; WURK AI drafts each
 * language on the same timings; a language reviewer approves them before they're shown in the help centre and the
 * "?" panel, with the reader's language on by default.
 */

uses(InteractsWithIdentity::class);

const HELP_VIDEO_EN_VTT = "WEBVTT\n\n00:00.000 --> 00:03.000\nOpen Bookings, then Services.\n\n00:03.000 --> 00:06.500\nTap Add service and give it a price.\n";

beforeEach(function () {
    Storage::fake('public');
    SimulatedDriver::$answers = [];
    $this->hq = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $this->help = fn (string $path) => 'http://'.config('wurk.domains.help').$path;
    $this->actingAs($this->hq)->post($this->admin('/help/categories'), ['name' => 'Bookings']);
    $this->actingAs($this->hq)->post($this->admin('/help/new'), ['category_id' => (string) DB::table('help_categories')->value('id'), 'title' => 'Add a service',
        'body' => 'Go to **Bookings → Services**.', 'status' => 'published', 'video' => UploadedFile::fake()->create('how.mp4', 3000, 'video/mp4')]);
    $this->article = HelpArticle::query()->sole();
    $this->edit = fn (array $over) => $this->actingAs($this->hq)->post($this->admin('/help/'.$this->article->id), [...['category_id' => $this->article->category_id,
        'title' => 'Add a service', 'body' => 'Go to **Bookings → Services**.', 'status' => 'published'], ...$over]);
});

it('takes English subtitles with the video, has WURK AI draft a language on the same timings, and shows it once a reviewer approves', function () {
    ($this->edit)(['captions' => 'not subtitles'])->assertSessionHasErrors('captions');
    ($this->edit)(['captions' => HELP_VIDEO_EN_VTT])->assertSessionHasNoErrors();
    $this->actingAs($this->hq)->get($this->admin('/help/'.$this->article->id))->assertInertia(fn (Assert $p) => $p->where('article.captions', HELP_VIDEO_EN_VTT));
    $this->get(($this->help)('/a/add-a-service'))->assertSee('<track kind="subtitles" srclang="en" label="English"', false)->assertDontSee('srclang="zu"', false);

    // Admin → Languages → isiZulu: draft with WURK AI (in the background), then review line by line.
    Queue::fake();
    $page = $this->admin('/settings/languages/zu');
    $this->actingAs($this->hq)->post($page.'/captions')->assertSessionHas('status');
    Queue::assertPushed(DraftCaptionsJob::class, fn (DraftCaptionsJob $j) => $j->locale === 'zu');
    SimulatedDriver::$answers = ['["Vula i-Bookings, bese u-Services.", "Thepha u-Add service bese uyinika intengo."]'];
    expect(app(CaptionDrafts::class)->draft('zu'))->toBe(1);
    $draft = HelpVideoCaption::query()->where('locale', 'zu')->sole();
    expect($draft->status)->toBe('draft')->and($draft->drafted_by_ai)->toBeTrue()
        ->and($draft->vtt)->toContain("00:03.000 --> 00:06.500\nThepha u-Add service bese uyinika intengo."); // the English timings
    $this->get(($this->help)('/a/add-a-service/captions/zu.vtt'))->assertNotFound(); // not shown until approved

    $this->actingAs($this->hq)->get($page)->assertInertia(fn (Assert $p) => $p->where('captions.0.status', 'draft')
        ->where('captions.0.english', ['Open Bookings, then Services.', 'Tap Add service and give it a price.']));
    $this->actingAs($this->hq)->put($page.'/captions/'.$this->article->id, ['lines' => ['Only one line'], 'approved' => true])->assertSessionHas('warning');
    $this->actingAs($this->hq)->put($page.'/captions/'.$this->article->id, ['lines' => ['Vula i-Bookings, bese uthinta u-Services.', 'Thepha u-Add service, ubeke intengo.'], 'approved' => true])
        ->assertSessionHas('status', 'Approved — shown with the video.');

    $this->get(($this->help)('/a/add-a-service'))->assertSee('srclang="zu" label="isiZulu"', false);
    $this->get(($this->help)('/a/add-a-service/captions/zu.vtt'))->assertOk()->assertHeader('Content-Type', 'text/vtt; charset=utf-8')
        ->assertSee("WEBVTT\n\n00:00.000 --> 00:03.000\nVula i-Bookings, bese uthinta u-Services.", false);
    $this->actingAs($this->hq)->getJson('http://'.config('wurk.domains.admin').'/_help?q=service')
        ->assertJsonPath('articles.0.tracks.1', ['locale' => 'zu', 'label' => 'isiZulu', 'url' => 'http://'.config('wurk.domains.admin').'/_help/captions/add-a-service/zu.vtt']);

    // New English words send the translation back for review; new timings mean it's drafted again.
    ($this->edit)(['captions' => str_replace('give it a price', 'set its price', HELP_VIDEO_EN_VTT)]);
    expect(HelpVideoCaption::query()->where('locale', 'zu')->value('status'))->toBe('draft');
    ($this->edit)(['captions' => str_replace('00:06.500', '00:07.000', HELP_VIDEO_EN_VTT)]);
    expect(HelpVideoCaption::query()->where('locale', 'zu')->exists())->toBeFalse();
});

it('keeps reviewing to people with the languages permission', function () {
    ($this->edit)(['captions' => HELP_VIDEO_EN_VTT]);
    $support = $this->staffWithRole('support', 'help@wurk.co.za');
    $this->actingAs($support)->put($this->admin('/settings/languages/zu/captions/'.$this->article->id), ['lines' => ['a', 'b'], 'approved' => true])->assertForbidden();
    expect(HelpVideoCaption::query()->where('locale', 'zu')->exists())->toBeFalse();
});
