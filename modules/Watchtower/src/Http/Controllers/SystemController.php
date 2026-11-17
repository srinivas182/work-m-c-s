<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
use Wurk\Tenancy\Contracts\ShardStatus;
use Wurk\Watchtower\Support\Backups;
use Wurk\Watchtower\Support\Release;
use Wurk\Watchtower\Support\SystemChecks;

/**
 * Admin → System (Sprint 18.4c): is WURK's data safe and up to date? The central database's and every shard's migration
 * state, the latest backup of each part, the latest restore drills against the targets, and recent business moves and
 * restores. Read-only: the commands in docs/runbooks/restore.md do the work.
 */
final class SystemController extends Controller
{
    public function __invoke(ShardStatus $shards, Migrator $migrator): Response
    {
        $checks = collect(SystemChecks::latest())->keyBy('name');

        return Inertia::render('Watchtower::System', [
            'release' => Release::current(),
            'central' => $this->central($migrator) + ['ok' => (bool) ($checks['database']['ok'] ?? true)],
            'shards' => $shards->shards(),
            'backups' => Backups::latest(),
            'backup_max_age_hours' => (int) config('wurk.watchtower.backup_max_age_hours', 26),
            'drills' => Backups::drills(),
            'targets' => ['rpo_minutes' => (int) config('wurk.watchtower.rpo_minutes', 15), 'rto_minutes' => (int) config('wurk.watchtower.rto_minutes', 240)],
            'moves' => $shards->recentMoves(),
        ]);
    }

    /** @return array{applied: int, pending: list<string>} */
    private function central(Migrator $migrator): array
    {
        try {
            $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
            $ran = $migrator->getRepository()->getRan();

            return ['applied' => count($ran), 'pending' => array_values(array_diff($files, $ran))];
        } catch (Throwable) {
            return ['applied' => 0, 'pending' => []];
        }
    }
}
