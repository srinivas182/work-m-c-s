<?php

declare(strict_types=1);

namespace Wurk\Website\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

/** Reports → Website (Sprint 15.2): visitors, page views, top pages, where visitors came from, and what they bought or booked. */
final readonly class WebsiteSection
{
    public function __construct(private TenantContext $context) {}

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        $tz = $this->context->current()->timezone();
        $days = fn (CarbonImmutable $a, CarbonImmutable $b) => [$a->setTimezone($tz)->toDateString(), $b->setTimezone($tz)->toDateString()];
        [$now, $before] = [$this->stats(...$days($p->from, $p->to)), $this->stats(...$days($p->previousFrom(), $p->previousTo()))];
        $highlights = [];
        if ($now['visitors'] > 0) {
            $top = array_key_first($now['sources']);
            $highlights[] = "Most website visitors came from {$top}.";
        }
        if ($now['conversions'] > 0 && ($best = array_key_first($now['converting'])) !== null) {
            $highlights[] = "Visitors from {$best} made the most orders and bookings.";
        }
        $rows = [];
        foreach ($now['sources'] as $source => $visitors) {
            $rows[] = [$source, (string) $visitors, (string) ($now['converting'][$source] ?? 0)];
        }

        return [
            'title' => 'Website',
            'metrics' => [
                ['label' => 'Visitors', 'value' => $now['visitors'], 'previous' => $before['visitors'], 'format' => 'count'],
                ['label' => 'Page views', 'value' => $now['views'], 'previous' => $before['views'], 'format' => 'count'],
                ['label' => 'Orders and bookings from the site', 'value' => $now['conversions'], 'previous' => $before['conversions'], 'format' => 'count'],
            ],
            'chart' => null,
            'rows' => ['columns' => ['Where visitors came from', 'Visitors', 'Orders and bookings'], 'data' => $rows],
            'extra' => ['columns' => ['Top pages', 'Views'], 'data' => $now['pages']],
            'highlights' => $highlights,
            'note' => 'Counted without cookies: no consent banner needed, and visitors can’t be identified.',
        ];
    }

    /** @return array{visitors: int, views: int, conversions: int, sources: array<string, int>, converting: array<string, int>, pages: list<list<string>>} */
    private function stats(string $from, string $to): array
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $visitors = $db->table('site_visitors')->where('tenant_id', $t)->whereBetween('day', [$from, $to])->get(['source']);
        $views = $db->table('site_page_views')->where('tenant_id', $t)->whereBetween('day', [$from, $to])->get(['path', 'views']);
        $conversions = $db->table('site_conversions')->where('tenant_id', $t)->whereBetween('day', [$from, $to])->get(['source']);
        $pages = $views->groupBy('path')->map(fn ($g) => (int) $g->sum('views'))->sortDesc()->take(5);

        return ['visitors' => $visitors->count(), 'views' => (int) $views->sum('views'), 'conversions' => $conversions->count(),
            'sources' => array_map('intval', $visitors->countBy('source')->sortDesc()->all()), 'converting' => array_map('intval', $conversions->countBy('source')->sortDesc()->all()),
            'pages' => array_values(array_map(fn ($path, $n) => [(string) $path, (string) $n], $pages->keys()->all(), $pages->values()->all()))];
    }
}
