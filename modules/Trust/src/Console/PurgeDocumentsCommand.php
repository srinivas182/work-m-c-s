<?php

declare(strict_types=1);

namespace Wurk\Trust\Console;

use Illuminate\Console\Command;
use Wurk\Trust\Support\Verifications;

/** Daily (25.4): deletes ID documents and selfies 90 days after HQ's decision. */
final class PurgeDocumentsCommand extends Command
{
    protected $signature = 'trust:purge-documents';

    protected $description = 'Delete verification documents kept past 90 days after the decision';

    public function handle(Verifications $verifications): int
    {
        $this->info($verifications->purge().' business(es) cleared.');

        return self::SUCCESS;
    }
}
