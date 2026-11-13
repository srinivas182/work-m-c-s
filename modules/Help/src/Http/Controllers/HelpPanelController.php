<?php

declare(strict_types=1);

namespace Wurk\Help\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Support\HelpCentre;

/** The "?" side panel on every back-office screen (Sprint 16.1b): that screen's help, or a search. */
final class HelpPanelController
{
    public function __invoke(Request $request, HelpCentre $help): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $cards = $q !== '' ? $help->search($q) : $help->forScreen((string) $request->query('screen', ''));
        $articles = HelpArticle::query()->where('status', 'published')->whereIn('slug', array_column($cards, 'slug'))->get()->keyBy('slug');

        return response()->json(['articles' => array_values(array_filter(array_map(function (array $c) use ($articles, $help): ?array {
            $a = $articles[$c['slug']] ?? null;

            return $a === null ? null : [...$c, 'html' => $help->render($a->body), 'video' => $help->url($a->video_path), 'poster' => $help->url($a->poster_path)];
        }, $cards))), 'home' => 'https://'.config('wurk.domains.help').'/']);
    }
}
