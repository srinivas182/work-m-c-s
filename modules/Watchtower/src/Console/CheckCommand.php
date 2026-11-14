<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Console;

use Illuminate\Console\Command;
use Wurk\Watchtower\Support\Alerts;
use Wurk\Watchtower\Support\Recorder;
use Wurk\Watchtower\Support\SlowPages;
use Wurk\Watchtower\Support\SystemChecks;

/** Every minute (Sprint 18.0): check every part of the platform, then email whatever problems are due. */
final class CheckCommand extends Command
{
    protected $signature = 'watchtower:check';

    protected $description = 'Check databases, shards, cache, storage, queues, scheduled tasks and slow pages; email HQ about problems';

    public function handle(SystemChecks $checks, Alerts $alerts, SlowPages $slow, Recorder $recorder): int
    {
        $results = $checks->run();
        $failing = array_keys(array_filter($results, fn ($r) => ! $r['ok']));
        $slow->evaluate($recorder);          // hours that have ended: slow pages (18.0b)
        $alerts->requestAnalyses();          // the AI explains new problems in the background (18.0b)
        $sent = $alerts->sendDue();
        $this->info(($failing === [] ? 'All '.count($results).' checks passing.' : 'Failing: '.implode(', ', $failing).'.')." {$sent} alert(s) sent.");

        return self::SUCCESS;
    }
}
