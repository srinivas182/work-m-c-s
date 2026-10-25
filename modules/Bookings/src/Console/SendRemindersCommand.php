<?php

declare(strict_types=1);

namespace Wurk\Bookings\Console;

use Illuminate\Console\Command;
use Wurk\Bookings\Support\Reminders;

final class SendRemindersCommand extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Send booking reminders that are due';

    public function handle(Reminders $reminders): int
    {
        $this->info($reminders->sendDue().' reminder(s) sent.');

        return self::SUCCESS;
    }
}
