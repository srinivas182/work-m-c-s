<?php

declare(strict_types=1);

namespace Wurk\Shop\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Support\OrderPlacer;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Unpaid orders past their hold time are cancelled and their stock returned (across businesses). */
final class ExpireOrdersCommand extends Command
{
    protected $signature = 'shop:expire-orders';

    protected $description = 'Cancel unpaid orders past their hold time and return their stock';

    public function handle(TenantDirectory $tenants, TenantContext $context, OrderPlacer $placer): int
    {
        $count = 0;
        foreach (DB::table('shop_holds')->where('expires_at', '<', now())->get()->groupBy('tenant_id') as $tenantId => $holds) {
            $tenant = $tenants->find((string) $tenantId);
            if ($tenant === null) {
                DB::table('shop_holds')->whereIn('order_id', $holds->pluck('order_id'))->delete();

                continue;
            }
            $count += (int) $context->run($tenant, function () use ($holds, $placer): int {
                $n = 0;
                foreach (Order::query()->whereIn('id', $holds->pluck('order_id'))->get() as $order) {
                    if (in_array($order->status, Order::HOLDING, true)) {
                        $placer->release($order, 'expired', null);
                        $n++;
                    } else {
                        DB::table('shop_holds')->where('order_id', $order->id)->delete(); // paid or cancelled meanwhile
                    }
                }

                return $n;
            });
        }
        $this->info("{$count} order(s) expired.");

        return self::SUCCESS;
    }
}
