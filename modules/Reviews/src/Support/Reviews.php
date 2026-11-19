<?php

declare(strict_types=1);

namespace Wurk\Reviews\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Platform\Contracts\FinishedVisits;
use Wurk\Platform\Contracts\PublicReviews;
use Wurk\Platform\Events\ReviewsChanged;
use Wurk\Regions\Contracts\Countries;
use Wurk\Reviews\Models\Review;
use Wurk\Reviews\Models\ReviewRequest;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Honest reviews (Sprint 25.3).
 *
 * - After a visit or an order (2 to 26 hours after it finished), one message asks "How was it?" with a link: on
 *   WhatsApp when the business has connected its number, else by SMS. At most once per customer per 90 days, and
 *   never to a number that replied STOP.
 * - Everyone who answers is offered the same thank-you, the same link to the business's Google review page, and the
 *   same private word with the owner. There is no gating: Google's rules forbid sending only happy customers to
 *   Google, and the US FTC treats it as review suppression.
 * - Reviews show in date order. The owner may hide one only for a stated reason; it still counts in the average.
 */
final class Reviews implements PublicReviews
{
    /** Ask once a visit has been over this long… */
    public const AFTER_HOURS = 2;

    /** …and not after this. */
    public const WITHIN_HOURS = 26;

    /** One request per customer at most this often. */
    public const QUIET_DAYS = 90;

    /** A review link lasts this long. */
    public const LINK_DAYS = 30;

    public function __construct(private TenantContext $context, private FinishedVisits $visits, private WhatsAppBusiness $whatsapp, private Messenger $messenger,
        private Countries $countries, private AuditLog $audit) {}

    /** @return array{requests_on: bool, google_url: string|null} */
    public function settings(): array
    {
        $row = DB::connection($this->context->connectionName())->table('review_settings')->where('tenant_id', $this->context->id())->first();

        return ['requests_on' => $row === null ? true : (bool) $row->requests_on, 'google_url' => $row?->google_url];
    }

    public function saveSettings(bool $requestsOn, ?string $googleUrl, ?string $by): void
    {
        $before = $this->settings();
        DB::connection($this->context->connectionName())->table('review_settings')->updateOrInsert(['tenant_id' => $this->context->id()],
            ['requests_on' => $requestsOn, 'google_url' => $googleUrl, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('reviews.settings', 'reviews', $this->context->id(), ['before' => $before, 'after' => $this->settings(), 'by' => $by]);
    }

    /** Asks the customers whose visit or order has just finished. Returns how many were asked. */
    public function askDue(?CarbonImmutable $now = null): int
    {
        if (! $this->settings()['requests_on']) {
            return 0;
        }
        $now ??= CarbonImmutable::now();
        $asked = 0;
        foreach ($this->visits->between($now->subHours(self::WITHIN_HOURS), $now->subHours(self::AFTER_HOURS)) as $visit) {
            $phone = $this->phone($visit['phone']);
            if ($phone === null || ReviewRequest::query()->where('visit_key', $visit['key'])->exists()) {
                continue;
            }
            if ($this->stopped($phone) || ReviewRequest::query()->where('phone', $phone)->where('sent_at', '>=', $now->subDays(self::QUIET_DAYS))->exists()) {
                continue;
            }
            $request = ReviewRequest::query()->create(['visit_key' => $visit['key'], 'kind' => $visit['kind'], 'title' => mb_substr($visit['title'], 0, 120),
                'customer_name' => mb_substr($visit['customer'], 0, 120), 'phone' => $phone, 'channel' => 'none']);
            $channel = $this->send($request);
            $request->update(['channel' => $channel, 'sent_at' => $channel === 'none' ? null : now()]);
            $asked += $channel === 'none' ? 0 : 1;
        }

        return $asked;
    }

    public function link(ReviewRequest $request): string
    {
        return URL::temporarySignedRoute('reviews.public.show', now()->addDays(self::LINK_DAYS), ['handle' => $this->context->current()->handle(), 'request' => $request->id]);
    }

    /** The customer's answer: once per request. */
    public function answer(ReviewRequest $request, int $rating, ?string $comment, ?string $privateNote): Review
    {
        if ($request->answered_at !== null) {
            throw ValidationException::withMessages(['rating' => __('Thank you — you’ve already told us how it was.')]);
        }
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => __('Choose from 1 to 5 stars.')]);
        }
        $clean = fn (?string $t, int $max) => ($t = trim(strip_tags((string) $t))) === '' ? null : mb_substr($t, 0, $max);

        $review = DB::connection($this->context->connectionName())->transaction(function () use ($request, $rating, $comment, $privateNote, $clean): Review {
            $review = Review::query()->create(['request_id' => $request->id, 'name' => self::shortName($request->customer_name), 'title' => $request->title,
                'rating' => $rating, 'comment' => $clean($comment, 1000), 'private_note' => $clean($privateNote, 1000)]);
            $request->update(['answered_at' => now()]);
            $this->audit->record('reviews.received', 'review', $review->id, ['rating' => $rating]);

            return $review;
        });
        event(new ReviewsChanged($this->context->id())); // the site's Reviews block is re-rendered

        return $review;
    }

    public function reply(Review $review, ?string $text, string $by): void
    {
        $text = trim(strip_tags((string) $text));
        $review->update(['reply' => $text === '' ? null : mb_substr($text, 0, 1000), 'replied_at' => $text === '' ? null : now()]);
        $this->audit->record('reviews.replied', 'review', $review->id, ['by' => $by]);
        event(new ReviewsChanged($this->context->id()));
    }

    public function hide(Review $review, string $reason, string $by): void
    {
        if (! array_key_exists($reason, Review::HIDE_REASONS)) {
            throw ValidationException::withMessages(['reason' => __('Choose why it’s hidden.')]);
        }
        $review->update(['hidden_reason' => $reason, 'hidden_by' => $by, 'hidden_at' => now()]);
        $this->audit->record('reviews.hidden', 'review', $review->id, ['reason' => $reason, 'by' => $by]);
        event(new ReviewsChanged($this->context->id()));
    }

    public function unhide(Review $review, string $by): void
    {
        $review->update(['hidden_reason' => null, 'hidden_by' => null, 'hidden_at' => null]);
        $this->audit->record('reviews.shown', 'review', $review->id, ['by' => $by]);
        event(new ReviewsChanged($this->context->id()));
    }

    /**
     * What the business's site shows: every review counts in the average (hidden ones too); the list has only the
     * shown ones, newest first, never sorted by stars.
     *
     * @return array{count: int, average: float|null, reviews: list<array{name: string, rating: int, comment: string|null, reply: string|null, date: string}>}
     */
    public function summary(int $limit = 12): array
    {
        $count = Review::query()->count();
        $average = $count === 0 ? null : round((float) Review::query()->avg('rating'), 1);
        $shown = Review::query()->whereNull('hidden_at')->orderByDesc('created_at')->limit($limit)->get();

        return ['count' => $count, 'average' => $average, 'reviews' => array_values($shown->map(fn (Review $r) => ['name' => $r->name, 'rating' => $r->rating,
            'comment' => $r->comment, 'reply' => $r->reply, 'date' => $r->created_at->format('Y-m-d')])->all())];
    }

    /** A customer replied STOP: no more review requests to that number from this business. */
    public function stop(string $phone): void
    {
        $phone = $this->phone($phone);
        if ($phone !== null) {
            DB::connection($this->context->connectionName())->table('review_stops')->insertOrIgnore(['tenant_id' => $this->context->id(), 'phone' => $phone, 'stopped_at' => now()]);
        }
    }

    public function stopped(string $phone): bool
    {
        return DB::connection($this->context->connectionName())->table('review_stops')->where('tenant_id', $this->context->id())->where('phone', $phone)->exists();
    }

    /** "Thandi Mokoena" → "Thandi M." — all a review ever shows of a name. */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr((string) ($parts[0] ?? ''), 0, 30);
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.' : '';

        return trim($first.' '.$last) ?: __('A customer');
    }

    private function send(ReviewRequest $request): string
    {
        $phone = (string) $request->phone;
        $first = (string) (preg_split('/\s+/', trim($request->customer_name))[0] ?? '');
        $business = $this->context->current()->displayName();
        $link = $this->link($request);
        try {
            if ($this->whatsapp->templateReady(WhatsAppBusiness::REVIEW_REQUEST)
                && $this->whatsapp->sendTemplate(ltrim($phone, '+'), WhatsAppBusiness::REVIEW_REQUEST, [$first, $business, $request->title, $link])->ok) {
                return 'whatsapp';
            }
        } catch (Throwable) {
        }
        if (! $this->messenger->channelActive('sms')) {
            return 'none';
        }
        $text = __('Hi :name, thank you for choosing :business. How was your :what? It takes a few seconds: :link Reply STOP to get no more of these.',
            ['name' => $first, 'business' => $business, 'what' => $request->title, 'link' => $link]);
        try {
            return $this->messenger->sms($phone, (string) $text, 'review_request')->ok ? 'sms' : 'none';
        } catch (InsufficientCredits) {
            return 'none';
        }
    }

    /** International digits with a +, from the business's country when the number starts with 0. */
    private function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            $prefix = (string) ($this->countries->find($this->context->current()->countryCode())['phone_prefix'] ?? '27');
            $digits = $prefix.substr($digits, 1);
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+'.$digits : null;
    }
}
