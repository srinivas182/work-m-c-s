<?php

declare(strict_types=1);

namespace Wurk\Help\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Models\HelpCategory;

/** WURK's help centre (Sprint 16.1): published help, search, safe rendering, and what readers found useful. */
final class HelpCentre
{
    /** @return list<array{slug: string, name: string, summary: string|null, articles: int}> */
    public function categories(): array
    {
        $counts = HelpArticle::query()->where('status', 'published')->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');

        return array_values(HelpCategory::query()->orderBy('position')->orderBy('name')->get()->filter(fn (HelpCategory $c) => ($counts[$c->id] ?? 0) > 0)
            ->map(fn (HelpCategory $c) => ['slug' => $c->slug, 'name' => $c->name, 'summary' => $c->summary, 'articles' => (int) $counts[$c->id]])->all());
    }

    /** @return list<array<string, mixed>> */
    public function inCategory(string $categoryId): array
    {
        return $this->cards(HelpArticle::query()->where('status', 'published')->where('category_id', $categoryId)->orderBy('position')->orderBy('title')->get());
    }

    public function published(string $slug): ?HelpArticle
    {
        return HelpArticle::query()->where('status', 'published')->where('slug', $slug)->first();
    }

    /** @return list<array<string, mixed>> published help for a back-office screen (Inertia page name) */
    public function forScreen(string $screen): array
    {
        return $this->cards(HelpArticle::query()->where('status', 'published')->whereJsonContains('screens', $screen)->orderBy('position')->limit(6)->get());
    }

    /**
     * Search titles, summaries and text. A search that finds nothing is counted by its words only — no person — so WURK
     * knows what to write next.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $q = mb_substr(trim(preg_replace('/\s+/', ' ', mb_strtolower($query)) ?? ''), 0, 120);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $found = HelpArticle::query()->where('status', 'published')
            ->where(fn ($w) => $w->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(summary) like ?', [$like])->orWhereRaw('lower(body) like ?', [$like]))
            ->orderByRaw('case when lower(title) like ? then 0 else 1 end', [$like])->orderBy('title')->limit(20)->get();
        if ($found->isEmpty()) {
            $day = now()->toDateString();
            if (DB::table('help_searches')->where('query', $q)->where('day', $day)->increment('count') === 0) {
                DB::table('help_searches')->insertOrIgnore(['query' => $q, 'day' => $day, 'count' => 1]);
            }
        }

        return $this->cards($found);
    }

    /** Markdown, safely: raw HTML removed, unsafe links refused. */
    public function render(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function viewed(HelpArticle $article): void
    {
        HelpArticle::query()->whereKey($article->id)->increment('views');
    }

    public function feedback(HelpArticle $article, bool $helpful): void
    {
        HelpArticle::query()->whereKey($article->id)->increment($helpful ? 'helpful_yes' : 'helpful_no');
    }

    public function url(?string $path): ?string
    {
        return $path === null ? null : Storage::disk('public')->url($path);
    }

    /**
     * @param  iterable<HelpArticle>  $articles
     * @return list<array<string, mixed>>
     */
    private function cards(iterable $articles): array
    {
        $out = [];
        foreach ($articles as $a) {
            $out[] = ['slug' => $a->slug, 'title' => $a->title, 'summary' => $a->summary, 'video' => $a->video_path !== null,
                'url' => 'https://'.config('wurk.domains.help').'/a/'.$a->slug];
        }

        return $out;
    }
}
