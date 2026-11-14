<?php

declare(strict_types=1);

namespace Wurk\Help\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Models\HelpCategory;
use Wurk\Help\Support\HelpCentre;
use Wurk\Help\Support\Screens;

/** Admin → Help centre (Sprint 16.1a): categories, articles, videos; what readers found and couldn't find. */
final class HelpAdminController
{
    public function index(): Response
    {
        return Inertia::render('Help::Admin/Index', [
            'categories' => HelpCategory::query()->orderBy('position')->get(['id', 'slug', 'name', 'summary', 'position']),
            'articles' => HelpArticle::query()->orderBy('title')->get()->map(fn (HelpArticle $a) => ['id' => $a->id, 'title' => $a->title, 'status' => $a->status, 'category_id' => $a->category_id,
                'views' => $a->views, 'helpful_yes' => $a->helpful_yes, 'helpful_no' => $a->helpful_no, 'video' => $a->video_path !== null, 'screens' => count($a->screens ?? [])])->all(),
            'notFound' => DB::table('help_searches')->where('day', '>=', now()->subDays(30)->toDateString())->selectRaw('query, sum(count) as n')->groupBy('query')->orderByDesc('n')->limit(20)
                ->get()->map(fn ($r) => ['query' => (string) $r->query, 'count' => (int) $r->n])->all(),
        ]);
    }

    public function saveCategory(Request $request, AuditLog $audit): RedirectResponse
    {
        $d = $request->validate(['id' => ['nullable', 'string', 'exists:help_categories,id'], 'name' => ['required', 'string', 'max:80'], 'summary' => ['nullable', 'string', 'max:200'], 'position' => ['nullable', 'integer', 'min:0', 'max:1000']]);
        $c = isset($d['id']) ? HelpCategory::query()->findOrFail((string) $d['id']) : new HelpCategory(['slug' => $this->slug(HelpCategory::class, (string) $d['name'])]);
        $c->fill(['name' => $d['name'], 'summary' => $d['summary'] ?? null, 'position' => (int) ($d['position'] ?? 100)])->save();
        $audit->record('help.category_saved', 'help_category', $c->id, []);

        return back()->with('status', 'Saved.');
    }

    public function create(Screens $screens): Response
    {
        return Inertia::render('Help::Admin/Edit', ['article' => null, 'categories' => HelpCategory::query()->orderBy('position')->get(['id', 'name']), 'screens' => $screens->all()]);
    }

    public function store(Request $request, AuditLog $audit): RedirectResponse
    {
        $a = new HelpArticle;
        $this->save($request, $a);
        $audit->record('help.article_created', 'help_article', $a->id, ['status' => $a->status]);

        return redirect()->to(url('/help/'.$a->id))->with('status', 'Saved.');
    }

    public function edit(string $article, Screens $screens, HelpCentre $help): Response
    {
        $a = HelpArticle::query()->findOrFail($article);

        return Inertia::render('Help::Admin/Edit', ['article' => [...$a->only(['id', 'category_id', 'slug', 'title', 'summary', 'body', 'status', 'position']), 'screens' => $a->screens ?? [],
            'video' => $help->url($a->video_path), 'poster' => $help->url($a->poster_path), 'url' => 'https://'.config('wurk.domains.help').'/a/'.$a->slug],
            'categories' => HelpCategory::query()->orderBy('position')->get(['id', 'name']), 'screens' => $screens->all()]);
    }

    public function update(Request $request, string $article, AuditLog $audit): RedirectResponse
    {
        $a = HelpArticle::query()->findOrFail($article);
        $this->save($request, $a);
        $audit->record('help.article_saved', 'help_article', $a->id, ['status' => $a->status]);

        return back()->with('status', 'Saved.');
    }

    /** An image for an article's text: returns the Markdown to insert. */
    public function image(Request $request, string $article): JsonResponse
    {
        HelpArticle::query()->findOrFail($article);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $path = (string) $request->file('image')?->storePublicly('help/images', 'public');

        return response()->json(['markdown' => '![]('.app(HelpCentre::class)->url($path).')']);
    }

    private function save(Request $request, HelpArticle $a): void
    {
        $d = $request->validate([
            'category_id' => ['required', 'string', 'exists:help_categories,id'], 'title' => ['required', 'string', 'max:150'], 'summary' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:100000'], 'status' => ['required', Rule::in(['draft', 'published'])], 'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'screens' => ['nullable', 'array', 'max:20'], 'screens.*' => ['string', 'max:120', 'regex:/^[A-Za-z]+::[A-Za-z\/]+$/'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm', 'max:40960'], 'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_video' => ['boolean'],
        ], ['video.max' => 'Keep videos under 40 MB (about 30 seconds).', 'screens.*.regex' => 'Choose screens from the list.']);
        if ($a->slug === null || $a->slug === '') {
            $a->slug = $this->slug(HelpArticle::class, (string) $d['title']);
        }
        if ($request->hasFile('video')) {
            $a->video_path = (string) $request->file('video')?->storePublicly('help/videos', 'public');
        } elseif ($request->boolean('remove_video')) {
            $a->video_path = null;
            $a->poster_path = null;
        }
        if ($request->hasFile('poster')) {
            $a->poster_path = (string) $request->file('poster')?->storePublicly('help/posters', 'public');
        }
        $a->fill(['category_id' => $d['category_id'], 'title' => $d['title'], 'summary' => $d['summary'] ?? null, 'body' => $d['body'], 'status' => $d['status'],
            'position' => (int) ($d['position'] ?? 100), 'screens' => array_values(array_unique($d['screens'] ?? [])), 'updated_by' => (string) Auth::id()]);
        if ($d['status'] === 'published' && $a->published_at === null) {
            $a->published_at = now();
        }
        $a->save();
    }

    /** @param class-string<HelpArticle|HelpCategory> $model */
    private function slug(string $model, string $from): string
    {
        $base = Str::slug($from) ?: 'help';
        $slug = $base;
        for ($i = 2; $model::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
