<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Console;

use Illuminate\Console\Command;
use Wurk\Watchtower\Support\Releases;

/** Sprint 17.2: a release's steps after migrating (config/release.php) — each version's once, the rest every deploy. */
final class ReleaseTasksCommand extends Command
{
    protected $signature = 'release:tasks {--dry-run : List what would run}';

    protected $description = 'Run the release steps that haven’t run in this environment yet (after migrating)';

    public function handle(Releases $releases): int
    {
        if ($this->option('dry-run')) {
            foreach ($releases->pending() as $step) {
                $this->line(($step['version'] === 'every' ? 'every deploy' : $step['version']).': '.$step['command']);
            }

            return self::SUCCESS;
        }
        [$ran, $failed] = $releases->run(fn (string $command) => $this->components->task($command));
        if ($failed !== null) {
            $this->components->error("Stopped: “{$failed}” failed. Nothing after it ran; fix it and deploy again.");

            return self::FAILURE;
        }
        $this->components->info($ran === 0 ? 'Nothing to run.' : "{$ran} release step".($ran === 1 ? '' : 's').' done.');

        return self::SUCCESS;
    }
}
