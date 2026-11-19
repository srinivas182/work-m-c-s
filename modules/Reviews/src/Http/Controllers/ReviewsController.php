<?php

declare(strict_types=1);

namespace Wurk\Reviews\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Reviews\Models\Review;
use Wurk\Reviews\Models\ReviewRequest;
use Wurk\Reviews\Support\Reviews;

/**
 * Customers → Reviews (25.3): every review, newest first, with the low scores still waiting for a reply at the top so
 * the owner can put things right; replies; hiding only for a stated reason; and the review settings.
 */
final class ReviewsController
{
    public function __construct(private Reviews $reviews) {}

    public function index(): Response
    {
        $all = Review::query()->orderByDesc('created_at')->limit(200)->get();
        $needs = $all->filter(fn (Review $r) => $r->rating <= 3 && $r->reply === null && $r->hidden_at === null);
        $ordered = $needs->concat($all->diff($needs))->values();

        return Inertia::render('Reviews::Index', [
            'summary' => array_intersect_key($this->reviews->summary(), array_flip(['count', 'average'])),
            'needsReply' => $needs->count(),
            'reviews' => $ordered->map(fn (Review $r) => ['id' => $r->id, 'name' => $r->name, 'title' => $r->title, 'rating' => $r->rating, 'comment' => $r->comment,
                'private_note' => $r->private_note, 'reply' => $r->reply, 'hidden_reason' => $r->hidden_reason, 'date' => $r->created_at->format('j M Y')])->all(),
            'asked' => ['month' => ReviewRequest::query()->whereNotNull('sent_at')->where('sent_at', '>=', now()->startOfMonth())->count(),
                'answered' => ReviewRequest::query()->whereNotNull('answered_at')->where('answered_at', '>=', now()->startOfMonth())->count()],
            'settings' => $this->reviews->settings(),
            'hideReasons' => array_map(fn (string $l) => __($l), Review::HIDE_REASONS),
        ]);
    }

    public function reply(Request $request, string $review): RedirectResponse
    {
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:1000']]);
        $this->reviews->reply(Review::query()->findOrFail($review), $data['reply'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Reply saved.'));
    }

    public function hide(Request $request, string $review): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(array_keys(Review::HIDE_REASONS))]], ['reason.*' => __('Choose why it’s hidden.')]);
        $this->reviews->hide(Review::query()->findOrFail($review), $data['reason'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Hidden from your website. It still counts in your average.'));
    }

    public function unhide(Request $request, string $review): RedirectResponse
    {
        $this->reviews->unhide(Review::query()->findOrFail($review), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Shown on your website again.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'requests_on' => ['required', 'boolean'],
            'google_url' => ['nullable', 'url:https', 'max:300', 'regex:#^https://(g\.page|www\.google\.[a-z.]+|google\.[a-z.]+|maps\.app\.goo\.gl|search\.google\.com|g\.co)/#i'],
        ], ['google_url.regex' => __('Paste the review link from your Google Business Profile (it starts with https://g.page/ or https://search.google.com/).'),
            'google_url.url' => __('Paste the review link from your Google Business Profile (it starts with https://g.page/ or https://search.google.com/).')]);
        $this->reviews->saveSettings((bool) $data['requests_on'], ($data['google_url'] ?? '') === '' ? null : $data['google_url'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Saved.'));
    }
}
