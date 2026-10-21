<?php

declare(strict_types=1);

namespace Wurk\Audit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Audit\Chain\Recorder;
use Wurk\Audit\Chain\Verifier;
use Wurk\Tenancy\Contracts\TenantDirectory;

final class VerifyAuditCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the platform audit chain and every business audit chain';

    public function handle(Verifier $verifier, TenantDirectory $directory): int
    {
        $failures = 0;
        $chains = 0;

        $report = function (string $label, array $result) use (&$failures, &$chains): void {
            $chains++;
            if ($result['ok']) {
                $this->components->twoColumnDetail($label, "<fg=green>{$result['events']} events OK</>");
            } else {
                $failures++;
                $this->components->twoColumnDetail($label, '<fg=red>BROKEN</>');
                $this->components->error((string) $result['problem']);
            }
        };

        $report('platform', $verifier->verify(DB::connection(), Recorder::PLATFORM));

        foreach ($directory->shardConnections() as $connection) {
            $db = DB::connection($connection);
            foreach ($db->table('audit_chain_heads')->orderBy('scope')->pluck('scope') as $scope) {
                $tenant = $directory->find((string) $scope);
                $report($tenant?->handle() ?? (string) $scope, $verifier->verify($db, (string) $scope));
            }
        }

        $this->newLine();
        $failures === 0
            ? $this->components->info("All {$chains} audit chains are intact.")
            : $this->components->error("{$failures} of {$chains} audit chains are broken.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
