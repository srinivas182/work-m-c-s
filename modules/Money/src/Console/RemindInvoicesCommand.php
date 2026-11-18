<?php

declare(strict_types=1);

namespace Wurk\Money\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Wurk\Money\Support\Invoices;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Daily (09:00 SAST): payment reminders for overdue invoices, on days 1, 7 and 14 after their due date (23.5a). */
final class RemindInvoicesCommand extends Command
{
    protected $signature = 'money:remind-invoices';

    protected $description = 'Send payment reminders for overdue invoices';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        $ids = [];
        foreach ($tenants->shardConnections() as $connection) { // only businesses with an overdue invoice: one query per shard
            try {
                $ids = [...$ids, ...DB::connection($connection)->table('money_invoices')->where('status', 'sent')->where('reminders_on', true)
                    ->where('due_on', '<', now()->toDateString())->distinct()->pluck('tenant_id')->map(fn ($id) => (string) $id)->all()];
            } catch (Throwable $e) {
                report($e);
            }
        }
        $sent = 0;
        foreach ($tenants->activeAmong(array_values(array_unique($ids))) as $tenant) {
            try {
                $sent += (int) $context->run($tenant, fn () => app(Invoices::class)->remindOverdue());
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
