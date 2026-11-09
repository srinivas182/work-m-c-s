<?php

declare(strict_types=1);

namespace Wurk\Reports\Console;

use Illuminate\Console\Command;
use Wurk\Reports\Support\ReportSummaries;

/** Daily: the owners' weekly/monthly summary emails that are due (Sprint 15.1b). */
final class SendSummariesCommand extends Command
{
    protected $signature = 'reports:send-summaries';

    protected $description = 'Email owners their weekly or monthly summary when it is due';

    public function handle(ReportSummaries $summaries): int
    {
        $this->info('Sent to '.$summaries->sendDue().' businesses.');

        return self::SUCCESS;
    }
}
