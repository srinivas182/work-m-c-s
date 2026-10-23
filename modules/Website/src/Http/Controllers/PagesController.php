<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\Templates;
use Wurk\Website\Support\TemplateSwitcher;

/** Owners: the list of website pages. */
final class PagesController
{
    public function __construct(private readonly Pages $pages) {}

    public function index(Request $request): Response
    {
        $this->pages->ensureSite();

        return Inertia::render('Website::Pages', [
            'pages' => Page::query()->orderBy('position')->get()->map(fn (Page $p) => [
                'id' => $p->id, 'title' => $p->title, 'slug' => $p->slug, 'is_home' => $p->is_home,
                'published' => $p->published_version_id !== null, 'updated_at' => $p->updated_at?->toIso8601String(),
            ])->all(),
            'deleted' => Page::onlyTrashed()->where('deleted_at', '>', now()->subDays(Pages::RESTORE_DAYS))->orderByDesc('deleted_at')->get()
                ->map(fn (Page $p) => ['id' => $p->id, 'title' => $p->title, 'deleted_at' => $p->deleted_at?->toIso8601String()])->all(),
            'canPublish' => (bool) $request->user()?->can('website.publish'),
            'base' => route('tenant.website.pages', [], false),
            'design' => $this->design(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'slug' => ['nullable', 'string', 'max:80']]);
        $this->pages->create($data['title'], $data['slug'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', "Page “{$data['title']}” added.");
    }

    public function update(Request $request, string $page): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'slug' => ['required', 'string', 'max:80'],
            'seo_title' => ['nullable', 'string', 'max:70'], 'seo_description' => ['nullable', 'string', 'max:170'],
        ]);
        $this->pages->update(Page::query()->findOrFail($page), $data);

        return back()->with('status', 'Page saved.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['string']]);
        $this->pages->reorder(array_values(array_map('strval', $data['order'])));

        return back();
    }

    public function home(string $page): RedirectResponse
    {
        $this->pages->setHome(Page::query()->findOrFail($page));

        return back()->with('status', 'Home page changed.');
    }

    public function destroy(string $page): RedirectResponse
    {
        $this->pages->delete(Page::query()->findOrFail($page));

        return back()->with('status', 'Page deleted. You can restore it for '.Pages::RESTORE_DAYS.' days.');
    }

    public function restore(string $page): RedirectResponse
    {
        $restored = $this->pages->restoreDeleted($page);

        return back()->with('status', "“{$restored->title}” restored.");
    }

    /** Change design: switch within the category, keeping content. */
    public function switchDesign(Request $request, TemplateSwitcher $switcher): RedirectResponse
    {
        $key = (string) $request->validate(['template' => ['required', 'string']])['template'];
        $switcher->switch($key, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Design changed. Your content came across — preview it, then publish when you’re happy.');
    }

    /** @return array{current: string|null, options: list<array{key: string, name: string, description: string|null}>}|null */
    private function design(): ?array
    {
        $site = Site::query()->find(app(TenantContext::class)->id());
        if ($site?->category_key === null) {
            return null;
        }
        $category = collect(app(Templates::class)->catalogue())->firstWhere('key', $site->category_key);

        return ['current' => $site->template_key, 'options' => $category['templates'] ?? []];
    }
}
