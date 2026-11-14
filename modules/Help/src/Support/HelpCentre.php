<?php

declare(strict_types=1);

namespace Wurk\Help\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Wurk\Help\Contracts\HelpSearch;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Models\HelpCategory;

/** WURK's help centre (Sprint 16.1): published help, search, safe rendering, and what readers found useful. */
final class HelpCentre implements HelpSearch
{
    /** Words that say nothing about what a question is about. */
    private const STOP = ['the', 'and', 'for', 'how', 'can', 'what', 'when', 'where', 'why', 'who', 'does', 'with', 'this', 'that', 'there', 'have', 'from', 'into', 'your', 'you', 'are', 'was', 'will', 'should', 'would', 'could', 'about', 'which', 'want', 'need', 'make', 'get', 'not', 'any', 'all', 'our', 'out', 'use', 'way'];

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

    public function relevant(string $question, int $limit = 3): array
    {
        $words = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [],
            fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOP, true),
        )));
        $words = array_slice($words, 0, 8);
        if ($words === []) {
            return [];
        }
        // Each word that appears scores; the title counts most. Few articles, so scoring in PHP is cheap.
        $found = HelpArticle::query()->where('status', 'published')->where(function ($w) use ($words): void {
            foreach ($words as $word) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $word).'%';
                $w->orWhereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(summary) like ?', [$like])->orWhereRaw('lower(body) like ?', [$like]);
            }
        })->limit(50)->get();
        $scored = $found->map(function (HelpArticle $a) use ($words): array {
            $title = mb_strtolower($a->title);
            $rest = mb_strtolower(($a->summary ?? '').' '.$a->body);
            $score = 0;
            foreach ($words as $word) {
                $score += (str_contains($title, $word) ? 3 : 0) + (str_contains($rest, $word) ? 1 : 0);
            }

            return ['score' => $score, 'article' => $a];
        })->sortByDesc('score')->take(max(1, $limit));

        return array_values($scored->map(fn (array $s) => [
            'slug' => $s['article']->slug, 'title' => $s['article']->title, 'summary' => $s['article']->summary,
            'url' => 'https://'.config('wurk.domains.help').'/a/'.$s['article']->slug, 'text' => mb_substr($s['article']->body, 0, 2000),
        ])->all());
    }

    public function missed(string $question): void
    {
        $q = mb_substr(trim(preg_replace('/\s+/', ' ', mb_strtolower($question)) ?? ''), 0, 120);
        if (mb_strlen($q) < 2) {
            return;
        }
        $day = now()->toDateString();
        if (DB::table('help_searches')->where('query', $q)->where('day', $day)->increment('count') === 0) {
            DB::table('help_searches')->insertOrIgnore(['query' => $q, 'day' => $day, 'count' => 1]);
        }
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
