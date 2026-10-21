<?php

declare(strict_types=1);

namespace Wurk\Audit\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Audit log viewer: WURK staff see the platform chain (admin host); owners see
 * their business's chain. Filters: action group, person, date range. CSV export.
 */
final class AuditLogController
{
    public function __invoke(Request $request, TenantContext $context): Response|StreamedResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'format' => ['nullable', 'in:csv'],
        ]);

        /** @var Builder<AuditEvent>|Builder<PlatformAuditEvent> $query */
        $query = $context->has() ? AuditEvent::query() : PlatformAuditEvent::query()->where('scope', 'platform');
        $query->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($filters['actor'] ?? null, fn ($q, $a) => $q->where('actor_name', 'like', '%'.$a.'%'))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', $d.' 23:59:59.999999'))
            ->orderByDesc('seq');

        if (($filters['format'] ?? null) === 'csv') {
            return $this->csv($query, $context, $context->has() ? $context->current() : null);
        }

        $page = $query->paginate(50)->withQueryString();

        return Inertia::render('Audit::Index', [
            'scope' => $context->has() ? $context->current()->displayName() : 'WURK platform',
            'filters' => array_filter($filters),
            'events' => [
                'data' => collect($page->items())->map(fn ($e) => [
                    'id' => $e->id,
                    'seq' => $e->seq,
                    'at' => $e->occurred_at->toIso8601String(),
                    'actor' => $e->actor_name ?? ($e->actor_type === 'system' ? 'System' : 'Unknown'),
                    'action' => $e->action,
                    'subject' => $e->subject_type ? trim($e->subject_type.' '.substr((string) $e->subject_id, -6)) : null,
                    'details' => $e->changes,
                    'ip' => $e->ip,
                ])->all(),
                'total' => $page->total(),
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /**
     * The body is streamed after the controller returns, possibly after the
     * request's business context has been cleared, so the stream re-enters it.
     *
     * @param  Builder<AuditEvent>|Builder<PlatformAuditEvent>  $query
     */
    private function csv(Builder $query, TenantContext $context, ?CurrentTenant $tenant): StreamedResponse
    {
        $name = $tenant?->handle() ?? 'platform';
        $write = function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['seq', 'occurred_at', 'actor', 'actor_type', 'action', 'subject_type', 'subject_id', 'details', 'ip', 'request_id', 'hash'], escape: '');
            foreach ($query->lazy(500) as $e) {
                fputcsv($out, [
                    $e->seq, $e->occurred_at->format('Y-m-d H:i:s.u'), $e->actor_name, $e->actor_type, $e->action,
                    $e->subject_type, $e->subject_id, json_encode($e->changes), $e->ip, $e->getAttribute('request_id'), $e->hash,
                ], escape: '');
            }
            fclose($out);
        };

        return response()->streamDownload(
            fn () => $tenant !== null ? $context->run($tenant, fn () => $write()) : $write(),
            "audit-{$name}-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
