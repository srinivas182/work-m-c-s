<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithShards;
use Wurk\Platform\Events\ReviewsChanged;
use Wurk\Reviews\Models\Review;
use Wurk\Reviews\Models\ReviewRequest;
use Wurk\Reviews\Support\Reviews;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Jobs\RenderPublishedPage;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;

/*
 * Sprint 25.3b: the Reviews section on a business's website. The page is rendered with the business's reviews (all
 * counted, the shown ones newest first), and pages showing reviews are re-rendered when one arrives or changes.
 */

uses(InteractsWithShards::class);

it('renders the site with its reviews and re-renders the pages that show them when a review arrives', function () {
    $this->createShards(1);
    $glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $in = fn (callable $fn) => app(TenantContext::class)->run($glow, $fn);
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Glow</h1>']))]);
    $this->artisan('website:install-templates', ['--only' => ['salon-hair-braids']]);

    $withReviews = $in(function () {
        Review::query()->create(['name' => 'Thandi M.', 'title' => 'Knotless braids', 'rating' => 5, 'comment' => 'Beautiful braids!']);
        app(SiteBuilder::class)->apply('salon-hair-braids');
        foreach (['home', 'services'] as $slug) {
            app(Pages::class)->publish(Page::query()->where('slug', $slug)->firstOrFail(), null, null);
        }
        $home = Page::query()->where('slug', 'home')->firstOrFail();
        $doc = json_decode((string) PageVersion::query()->whereKey($home->published_version_id)->value('content'), true);
        $doc['blocks'] = array_values(array_filter($doc['blocks'], fn (array $b) => $b['type'] !== 'Reviews'));
        $doc['blocks'][] = ['id' => 'customer-reviews', 'type' => 'Reviews', 'version' => 1, 'props' => []];
        app(Pages::class)->saveDraft($home, $doc, (int) $home->draft_revision, null);

        return (string) app(Pages::class)->publish($home->refresh(), null, null)->id;
    });
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->input, '"reviews":{"count":1,"average":5')
        && str_contains((string) $p->input, 'Beautiful braids!'));

    Queue::fake();
    $in(function () {
        $request = ReviewRequest::query()->create(['visit_key' => 'booking:1', 'kind' => 'booking', 'title' => 'Colour', 'customer_name' => 'Sipho Dlamini',
            'phone' => '+27825550202', 'channel' => 'sms', 'sent_at' => now()]);
        app(Reviews::class)->answer($request, 3, 'Good, but slow.', null);
    });
    Queue::assertPushed(RenderPublishedPage::class, 1);
    Queue::assertPushed(RenderPublishedPage::class, fn (RenderPublishedPage $job) => $job->versionId === $withReviews);

    Queue::fake();
    event(new ReviewsChanged('01J0000000000000000000NONE')); // an unknown business: nothing happens
    Queue::assertNothingPushed();
});
