<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;

uses(InteractsWithShards::class);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Wurk\Website\Mail\ChatHandoffMail;
use Wurk\Website\Models\ChatAnswer;
use Wurk\Website\Models\ChatHandoff;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\ChatBot;

beforeEach(function () {
    // Like the real renderer: report each block's final values, filling the hours section's defaults
    // (Monday–Friday 08:00–17:00, Saturday 08:00–13:00, Sunday closed) when the template leaves them out.
    Process::fake(['*' => function (PendingProcess $process) {
        $blocks = array_map(function (array $b) {
            if ($b['type'] === 'OpeningHours' && ! isset($b['props']['hours'])) {
                $b['props']['hours'] = [...array_fill(0, 5, ['opens' => '08:00', 'closes' => '17:00']), ['opens' => '08:00', 'closes' => '13:00'], ['opens' => '', 'closes' => '']];
            }

            return ['type' => $b['type'], 'props' => $b['props']];
        }, json_decode((string) $process->input, true)['document']['blocks']);

        return Process::result(output: (string) json_encode(['html' => '<main></main>', 'fonts' => [], 'blocks' => $blocks]));
    }]);
    $this->artisan('website:install-templates');
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->base = 'http://glow-salon.'.config('wurk.domains.root');
    $this->ctx->run($this->glow, fn () => app(SiteBuilder::class)->apply('salon-hair-braids'));
    $this->launch = fn () => $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        foreach (Page::query()->get() as $page) {
            $doc = json_decode((string) $page->draft, true);
            foreach ($doc['blocks'] as &$b) {
                if ($b['type'] === 'Contact') {
                    $b['props']['phone'] = '082 555 0101';
                }
                if ($b['type'] === 'ServicesPrices' && $b['props']['items'] !== []) {
                    $b['props']['items'][0]['price'] = 45000;
                    $b['props']['items'][0]['name'] = 'Knotless braids </script><script>alert(1)</script>';
                    $b['props']['items'][] = ['name' => 'Consultation', 'description' => '', 'duration' => '', 'price' => 0, 'from' => false];
                }
            }
            unset($b);
            $pages->saveDraft($page, $doc, $page->draft_revision, null);
            $pages->publish($page->refresh(), null, null);
        }
    });
    $this->turnOn = fn (array $extra = []) => $this->ctx->run($this->glow, function () use ($extra) {
        $site = Site::query()->firstOrFail();
        $site->update(['settings' => array_replace_recursive((array) $site->settings, ['chat' => ['enabled' => true]], $extra)]);
        app(ChatBot::class)->ensureReady();
    });
    $this->config = fn () => $this->getJson($this->base.'/chat')->assertOk()->json();
});

it('is off until switched on, then answers from what the business published', function () {
    ($this->launch)();
    $this->getJson($this->base.'/chat')->assertNotFound();
    ($this->turnOn)();
    $c = ($this->config)();
    $labels = array_column($c['buttons'], 'label');
    expect($labels)->toContain('Prices', 'Call us')->not->toContain('Opening hours') // hours aren't confirmed
        ->and(collect($c['buttons'])->firstWhere('label', 'Prices')['answer'])->toContain('R450')
        ->and(collect($c['buttons'])->firstWhere('label', 'Call us')['link'])->toBe(['kind' => 'call', 'href' => 'tel:0825550101'])
        ->and($c['reply'])->toBe('We’ll get back to you as soon as we can.')
        ->and(collect($c['keywords'])->pluck('words')->flatten()->all())->toContain('how much', 'call');
});

it('uses confirmed opening hours for an answer and for reply times', function () {
    ($this->launch)();
    ($this->turnOn)(['checklist' => ['hours' => true]]);
    $c = ($this->config)();
    expect(collect($c['buttons'])->firstWhere('label', 'Opening hours')['answer'])->toStartWith('We’re open 08:00–17:00')
        ->and($c['reply'])->toStartWith('We’ll get back to you during our opening hours (08:00–17:00');
});

it('follows the facts unless the owner rewrote an answer, and links only to the site’s own pages', function () {
    ($this->launch)();
    ($this->turnOn)();
    $this->ctx->run($this->glow, function () {
        ChatAnswer::query()->where('label', 'Prices')->update(['answer' => 'Ask us for a quote.', 'edited' => true]);
        ChatAnswer::query()->create(['kind' => 'button', 'label' => 'Gallery', 'answer' => 'See our work.', 'link_kind' => 'page', 'link_target' => '/nope', 'position' => 9]);
        $pages = app(Pages::class);
        foreach (Page::query()->get() as $page) {
            $doc = json_decode((string) $page->draft, true);
            foreach ($doc['blocks'] as &$b) {
                if ($b['type'] === 'Contact') {
                    $b['props']['phone'] = '083 000 0000';
                }
            }
            unset($b);
            $pages->saveDraft($page, $doc, $page->draft_revision, null);
            $pages->publish($page->refresh(), null, null);
        }
    });
    $c = ($this->config)();
    expect(collect($c['buttons'])->firstWhere('label', 'Call us')['answer'])->toBe('Call us on 083 000 0000.')   // followed the facts
        ->and(collect($c['buttons'])->firstWhere('label', 'Prices')['answer'])->toBe('Ask us for a quote.')         // the owner's words kept
        ->and(collect($c['buttons'])->firstWhere('label', 'Gallery')['link'])->toBeNull();                         // not one of the site's pages
});

it('hands a chat to a person: stored with consent, a contact and lead, and the owner emailed — spam and missing details refused', function () {
    Mail::fake();
    ($this->launch)();
    $chat = ['name' => 'Sipho', 'phone' => '082 555 0101', 'message' => 'Can I book a wedding party of 6?', 'consent' => '1',
        'transcript' => [['from' => 'bot', 'text' => 'Hi! How can we help?'], ['from' => 'visitor', 'text' => 'wedding group booking']]];
    $this->postJson($this->base.'/chat/handoff', $chat)->assertNotFound(); // off
    ($this->turnOn)(['enquiry_email' => 'owner@glow.co.za']);

    $this->postJson($this->base.'/chat/handoff', [...$chat, 'consent' => null])->assertStatus(422)->assertJsonValidationErrors('consent');
    $this->postJson($this->base.'/chat/handoff', [...$chat, 'phone' => null])->assertStatus(422)->assertJsonValidationErrors('phone');
    $this->postJson($this->base.'/chat/handoff', [...$chat, 'website' => 'spam.example'])->assertOk(); // a bot: “accepted”, nothing kept
    expect($this->ctx->run($this->glow, fn () => ChatHandoff::query()->count()))->toBe(0);

    $this->postJson($this->base.'/chat/handoff', $chat)->assertOk()->assertJsonPath('reply', 'Thanks — we’ll get back to you as soon as we can.');
    $this->ctx->run($this->glow, function () {
        $h = ChatHandoff::query()->sole();
        $db = DB::connection(app(TenantContext::class)->connectionName());
        expect($h->transcript)->toHaveCount(2)->and($h->contact_id)->not->toBeNull()->and($h->marketing_consent_at)->toBeNull()
            ->and($db->table('contact_links')->where('source_type', 'chat')->count())->toBe(1)
            ->and($db->table('leads')->value('title'))->toBe('Chat: Sipho')->and($db->table('leads')->value('source'))->toBe('chat');
    });
    Mail::assertSent(ChatHandoffMail::class, fn ($m) => $m->hasTo('owner@glow.co.za')
        && str_contains($m->render(), 'Them: wedding group booking') && str_contains($m->render(), 'Can I book a wedding party of 6?'));
});

it('counts taps on the business’s own buttons only — anonymously', function () {
    ($this->launch)();
    ($this->turnOn)();
    $this->postJson($this->base.'/chat/tap', ['label' => 'Prices'])->assertOk();
    $this->postJson($this->base.'/chat/tap', ['label' => 'Prices'])->assertOk();
    $this->postJson($this->base.'/chat/tap', ['label' => 'Not a button'])->assertOk();
    $rows = $this->ctx->run($this->glow, fn () => DB::connection(app(TenantContext::class)->connectionName())->table('chat_taps')->get(['label', 'taps'])->map(fn ($r) => (array) $r)->all());
    expect($rows)->toBe([['label' => 'Prices', 'taps' => 2]]);
});
