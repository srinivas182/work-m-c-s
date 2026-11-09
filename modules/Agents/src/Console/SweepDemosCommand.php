<?php

declare(strict_types=1);

namespace Wurk\Agents\Console;

use Illuminate\Console\Command;
use Wurk\Agents\Support\AgentDemos;

/** Daily: agents' demo sites — reminders on days 10 and 13; archived on day 14 (Sprint 14.8a). */
final class SweepDemosCommand extends Command
{
    protected $signature = 'agents:sweep-demos';

    protected $description = 'Remind agents about demo sites closing soon, and archive expired ones';

    public function handle(AgentDemos $demos): int
    {
        $r = $demos->sweep();
        $this->info("Reminded {$r['reminded']}, archived {$r['archived']}.");

        return self::SUCCESS;
    }
}
