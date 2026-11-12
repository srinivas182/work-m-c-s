<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Support\HelpCentre;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    Storage::fake('public');
    $this->admin_ = $this->staffWithRole('super-admin', 'admin@wurk.co.za');
    $this->help = fn (string $path) => 'http://'.config('wurk.domains.help').$path;
    $this->actingAs($this->admin_)->post($this->admin('/help/categories'), ['name' => 'Getting paid', 'summary' => 'Payments and payouts'])->assertSessionHas('status', 'Saved.');
    $this->category = (string) DB::table('help_categories')->value('id');
    $this->article = fn (array $over = []) => $this->actingAs($this->admin_)->post($this->admin('/help/new'), [...['category_id' => $this->category, 'title' => 'Turn on card payments',
        'summary' => 'Take card payments with Yoco or Paystack.', 'body' => "## Steps\n\nGo to **Settings → Payments**.<script>alert(1)</script>\n\n[Click](javascript:alert(1))",
        'status' => 'draft', 'screens' => ['Payments::Settings']], ...$over]);
});

it('publishes articles to help.wurk.co.za — drafts stay hidden, text rendered safely, views and feedback counted', function () {
    ($this->article)()->assertRedirect();
    $this->get(($this->help)('/a/turn-on-card-payments'))->assertNotFound();                         // a draft
    $id = (string) HelpArticle::query()->value('id');
    $this->actingAs($this->admin_)->post($this->admin('/help/'.$id), ['category_id' => $this->category, 'title' => 'Turn on card payments', 'body' => HelpArticle::query()->value('body'), 'status' => 'published'])
        ->assertSessionHas('status', 'Saved.');
    $page = $this->get(($this->help)('/a/turn-on-card-payments'))->assertOk();
    $page->assertSee('<h2>Steps</h2>', false)->assertSee('<strong>Settings → Payments</strong>', false)->assertDontSee('<script>', false)->assertDontSee('javascript:', false);
    $this->get(($this->help)('/'))->assertSee('Getting paid')->assertSee('1 article');
    expect(HelpArticle::query()->value('views'))->toBe(1);
    $this->post(($this->help)('/a/turn-on-card-payments/feedback'), ['helpful' => '1'])->assertRedirect('/a/turn-on-card-payments#feedback');
    $this->post(($this->help)('/a/turn-on-card-payments/feedback'), ['helpful' => '1']);              // once per visit
    expect(HelpArticle::query()->value('helpful_yes'))->toBe(1);
    $this->get(($this->help)('/a/turn-on-card-payments'))->assertSee('Thanks for telling us.');
});

it('searches, counts searches that found nothing (words only), and finds help for a screen', function () {
    ($this->article)(['status' => 'published']);
    $this->get(($this->help)('/search?q=CARD'))->assertSee('Turn on card payments');
    $this->get(($this->help)('/search?q=refund a deposit'));
    $this->get(($this->help)('/search?q=Refund  a deposit'));
    expect(DB::table('help_searches')->get(['query', 'count'])->map(fn ($r) => (array) $r)->all())->toBe([['query' => 'refund a deposit', 'count' => 2]]);
    $this->actingAs($this->admin_)->get($this->admin('/help'))->assertInertia(fn (Assert $p) => $p->where('notFound', [['query' => 'refund a deposit', 'count' => 2]]));
    expect(array_column(app(HelpCentre::class)->forScreen('Payments::Settings'), 'slug'))->toBe(['turn-on-card-payments'])
        ->and(app(HelpCentre::class)->forScreen('Shop::Products'))->toBe([]);
});

it('takes a 30-second video, refuses unknown screens, and is for staff who may write help', function () {
    ($this->article)(['status' => 'published', 'video' => UploadedFile::fake()->create('how.mp4', 5000, 'video/mp4')]);
    $this->get(($this->help)('/a/turn-on-card-payments'))->assertSee('<video controls preload="none"', false);
    ($this->article)(['title' => 'Other', 'screens' => ['not a screen']])->assertSessionHasErrors(['screens.0' => 'Choose screens from the list.']);
    $this->actingAs($this->staffWithRole('support', 'support@wurk.co.za'))->get($this->admin('/help'))->assertForbidden(); // the admin site refuses; business back offices hide
});
