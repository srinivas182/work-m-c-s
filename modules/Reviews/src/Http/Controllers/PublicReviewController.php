<?php

declare(strict_types=1);

namespace Wurk\Reviews\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Reviews\Models\ReviewRequest;
use Wurk\Reviews\Support\Reviews;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * The customer's "How was it?" page (25.3), on the business's own address, in the customer's language. A signed
 * link from the request message; one answer each. Afterwards everyone sees the same thank-you, the same link to
 * the business's Google page and the same way to tell the owner privately.
 */
final class PublicReviewController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private Reviews $reviews) {}

    public function show(string $handle, string $id): Response
    {
        return $this->within($handle, fn (CurrentTenant $t) => $this->form($t, ReviewRequest::query()->findOrFail($id), null));
    }

    private function form(CurrentTenant $t, ReviewRequest $review, ?string $problem): Response
    {
        return response()->view('reviews::public.review', [
            'business' => $t->displayName(), 'title' => $review->title, 'answered' => $review->answered_at !== null, 'problem' => $problem,
            'action' => URL::temporarySignedRoute('reviews.public.store', now()->addDay(), ['handle' => $t->handle(), 'request' => $review->id]),
            'google' => $this->reviews->settings()['google_url'],
        ], $problem === null ? 200 : 422)->header('X-Robots-Tag', 'noindex');
    }

    public function store(Request $request, string $handle, string $id): Response
    {
        return $this->within($handle, function (CurrentTenant $t) use ($request, $id) {
            $review = ReviewRequest::query()->findOrFail($id);
            $v = Validator::make($request->all(), ['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000'], 'private_note' => ['nullable', 'string', 'max:1000']],
                ['rating.required' => __('Choose from 1 to 5 stars.'), 'rating.between' => __('Choose from 1 to 5 stars.')]);
            if ($v->fails()) {
                return $this->form($t, $review, (string) $v->errors()->first());
            }
            $data = $v->validated();
            if ($review->answered_at === null) {
                $this->reviews->answer($review, (int) $data['rating'], $data['comment'] ?? null, $data['private_note'] ?? null);
            }

            return response()->view('reviews::public.thanks', ['business' => $t->displayName(), 'google' => $this->reviews->settings()['google_url'],
                'privately' => trim((string) ($data['private_note'] ?? '')) !== ''])->header('X-Robots-Tag', 'noindex');
        });
    }

    private function within(string $handle, \Closure $fn): Response
    {
        $tenant = $this->tenants->findByHandle($handle);
        if ($tenant === null || ! $tenant->isActive()) {
            abort(404);
        }

        return $this->context->run($tenant, fn () => $fn($tenant));
    }
}
