<?php

declare(strict_types=1);

namespace Wurk\Success\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Wurk\Security\Contracts\PlatformSettings;
use Wurk\Success\Models\BusinessHealth;
use Wurk\Success\Support\HealthDigest;

/** Admin → Business health (Sprint 16.5): every business's score with reasons and next steps; filter, sort, export. */
final class HealthController
{
    public const SORTS = ['mrr' => 'MRR at risk', 'score' => 'Lowest score', 'drop' => 'Biggest drop', 'name' => 'Name'];

    public function index(Request $request, HealthDigest $digest): Response
    {
        $f = $this->filters($request);
        $rows = $this->query($f)->paginate(50)->withQueryString();
        $counts = BusinessHealth::query()->toBase()->selectRaw('grade, count(*) as n, sum(mrr_cents) as mrr')->groupBy('grade')->get()->keyBy('grade');

        return Inertia::render('Success::Health', [
            'filters' => $f, 'grades' => BusinessHealth::GRADES, 'sorts' => self::SORTS,
            'packages' => BusinessHealth::query()->whereNotNull('package_key')->distinct()->orderBy('package_key')->pluck('package_key')->all(),
            'summary' => collect(BusinessHealth::GRADES)->map(fn ($l, $g) => ['count' => (int) ($counts[$g]->n ?? 0), 'mrr_cents' => (int) ($counts[$g]->mrr ?? 0)])->all(),
            'computed_at' => BusinessHealth::query()->max('computed_at'),
            'digest_email' => $digest->recipient(),
            'rows' => ['data' => collect($rows->items())->map(fn (BusinessHealth $h) => [
                'tenant_id' => $h->tenant_id, 'name' => $h->name, 'package' => $h->package_key, 'score' => $h->score, 'previous' => $h->previous_score,
                'grade' => $h->grade, 'mrr_cents' => $h->mrr_cents, 'signals' => $h->signals, 'at_risk_since' => $h->at_risk_since?->toIso8601String(),
            ])->all(), 'total' => $rows->total(), 'next' => $rows->nextPageUrl(), 'prev' => $rows->previousPageUrl()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $q = $this->query($this->filters($request));

        return response()->streamDownload(function () use ($q): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Business', 'Package', 'Score', 'Previous score', 'Grade', 'MRR (R)', 'Reasons', 'Suggested next step'], escape: '');
            $q->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $h) {
                    /** @var BusinessHealth $h */
                    fputcsv($out, [$h->name, $h->package_key, $h->score, $h->previous_score, BusinessHealth::GRADES[$h->grade] ?? $h->grade, number_format($h->mrr_cents / 100, 2, '.', ''),
                        implode('; ', array_column($h->signals, 'reason')), $h->signals[0]['action'] ?? ''], escape: '');
                }
            });
            fclose($out);
        }, 'business-health-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function settings(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $d = $request->validate(['digest_email' => ['nullable', 'email', 'max:160']]);
        $settings->set('success.digest_email', $d['digest_email'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Weekly digest email saved.');
    }

    /** @return array{grade: string, package: string, sort: string, q: string} */
    private function filters(Request $request): array
    {
        $grade = (string) $request->query('grade', '');
        $sort = (string) $request->query('sort', 'mrr');

        return ['grade' => array_key_exists($grade, BusinessHealth::GRADES) ? $grade : '', 'package' => mb_substr((string) $request->query('package', ''), 0, 40),
            'sort' => array_key_exists($sort, self::SORTS) ? $sort : 'mrr', 'q' => mb_substr(trim((string) $request->query('q', '')), 0, 80)];
    }

    /**
     * @param  array{grade: string, package: string, sort: string, q: string}  $f
     * @return Builder<BusinessHealth>
     */
    private function query(array $f): Builder
    {
        $q = BusinessHealth::query()
            ->when($f['grade'] !== '', fn ($q) => $q->where('grade', $f['grade']), fn ($q) => $f['sort'] === 'mrr' ? $q->whereIn('grade', ['at_risk', 'watch']) : $q)
            ->when($f['package'] !== '', fn ($q) => $q->where('package_key', $f['package']))
            ->when($f['q'] !== '', fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $f['q']).'%'));

        return match ($f['sort']) {
            'score' => $q->orderBy('score')->orderByDesc('mrr_cents'),
            'drop' => $q->orderByRaw('coalesce(previous_score, score) - score desc')->orderBy('score'),
            'name' => $q->orderBy('name'),
            default => $q->orderByDesc('mrr_cents')->orderBy('score'),
        };
    }
}
