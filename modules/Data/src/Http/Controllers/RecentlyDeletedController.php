<?php

declare(strict_types=1);

namespace Wurk\Data\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Contracts\CannotRestore;
use Wurk\Platform\Contracts\RecentlyDeleted;
use Wurk\Tenancy\Contracts\TenantContext;

/** Settings → Recently deleted (Sprint 16.6): the last 30 days of deletions this person may manage, each restorable. */
final class RecentlyDeletedController
{
    public function __construct(private readonly RecentlyDeleted $deleted, private readonly TenantContext $context) {}

    public function show(Request $request): Response
    {
        $since = now()->subDays(RecentlyDeleted::DAYS);
        $tz = $this->context->current()->timezone();
        $groups = [];
        foreach ($this->deleted->types() as $type => $t) {
            if (! ($request->user()?->can($t['permission']) ?? false)) {
                continue;
            }
            $items = array_map(fn (array $i) => ['id' => $i['id'], 'name' => $i['name'], 'deleted_at' => CarbonImmutable::instance($i['deleted_at'])->setTimezone($tz)->toIso8601String(),
                'gone_at' => CarbonImmutable::instance($i['deleted_at'])->addDays(RecentlyDeleted::DAYS)->setTimezone($tz)->toIso8601String()], ($t['list'])($since));
            if ($items !== []) {
                $groups[] = ['type' => $type, 'label' => $t['label'], 'items' => $items];
            }
        }

        return Inertia::render('Data::RecentlyDeleted', ['groups' => $groups, 'days' => RecentlyDeleted::DAYS]);
    }

    public function restore(Request $request, AuditLog $audit, string $type, string $id): RedirectResponse
    {
        $t = $this->deleted->types()[$type] ?? abort(404);
        abort_unless($request->user()?->can($t['permission']) ?? false, 404);
        try {
            $message = ($t['restore'])($id);
        } catch (CannotRestore $e) {
            return back()->with('warning', $e->getMessage());
        }
        $audit->record('data.restored', $type, $id);

        return back()->with('status', $message);
    }
}
