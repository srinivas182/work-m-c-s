<?php

declare(strict_types=1);

namespace Wurk\Loyalty;

use Illuminate\Support\Facades\Event;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Loyalty\Support\Loyalty;
use Wurk\Platform\Contracts\LoyaltyCards;
use Wurk\Platform\Modules\ModuleServiceProvider;

/**
 * Loyalty (Sprint 26.5): stamp cards on WhatsApp — stamped after each paid visit, order or sale, with one reward per
 * full card, offered at the next booking or checkout and used once.
 */
final class LoyaltyServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LoyaltyCards::class, Loyalty::class);
    }

    protected function bootModule(): void
    {
        Event::listen(PurchaseMade::class, function (PurchaseMade $e): void {
            $loyalty = app(Loyalty::class);
            if (($line = $loyalty->purchased($e->sourceType, $e->sourceId, $e->paidCents)) !== null) {
                $loyalty->tell($e->sourceType, $e->sourceId, $line);
            }
        });
    }

    public function permissions(): array
    {
        return [['key' => 'loyalty.manage', 'label' => 'Loyalty card: the reward, stamps and what counts']];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [['label' => 'Loyalty card', 'route' => 'tenant.loyalty', 'icon' => 'gift', 'section' => 'Customers', 'permission' => 'loyalty.manage', 'order' => 335]];
    }
}
