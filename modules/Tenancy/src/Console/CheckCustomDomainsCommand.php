<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Throwable;
use Wurk\Tenancy\Contracts\CustomHostnames;
use Wurk\Tenancy\Domains\CustomDomainManager;
use Wurk\Tenancy\Events\CustomDomainChanged;
use Wurk\Tenancy\Models\TenantDomain;

/**
 * Sprint 17.3: moves owners' domains on. Every few minutes, the pending ones (live as soon as they point at WURK);
 * with --all, once a day, every domain (one that stopped pointing at WURK lapses and the owner is told). A domain
 * still pending after `pending_days` is let go.
 */
final class CheckCustomDomainsCommand extends Command
{
    protected $signature = 'domains:check {--all : Active domains too (daily)}';

    protected $description = 'Check owners’ own domains with the domain service';

    public function handle(CustomDomainManager $domains, CustomHostnames $hostnames): int
    {
        if (! $hostnames->configured()) {
            return self::SUCCESS; // nothing to ask (local, CI)
        }
        $statuses = $this->option('all') ? [TenantDomain::PENDING, TenantDomain::ACTIVE, TenantDomain::LAPSED] : [TenantDomain::PENDING];
        $counts = [];
        $limit = now()->subDays((int) config('wurk.custom_domains.pending_days', 7));
        TenantDomain::query()->where('type', TenantDomain::TYPE_CUSTOM)->whereIn('status', $statuses)->orderBy('checked_at')
            ->each(function (TenantDomain $d) use ($domains, $hostnames, $limit, &$counts): void {
                try {
                    if ($d->status === TenantDomain::PENDING && $d->created_at !== null && $d->created_at->lt($limit)) {
                        if ($d->provider_id !== null) {
                            $hostnames->delete($d->provider_id);
                        }
                        $d->delete();
                        event(new CustomDomainChanged($d->tenant_id, $d->host, 'expired'));
                        $status = 'expired';
                    } else {
                        $status = $domains->refresh($d);
                    }
                    $counts[$status] = ($counts[$status] ?? 0) + 1;
                } catch (Throwable $e) {
                    report($e); // one domain never stops the others
                }
            }, 100);
        $this->components->info($counts === [] ? 'No domains to check.' : collect($counts)->map(fn ($n, $s) => "{$n} {$s}")->implode(', ').'.');

        return self::SUCCESS;
    }
}
