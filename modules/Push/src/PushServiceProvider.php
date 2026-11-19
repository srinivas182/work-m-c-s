<?php

declare(strict_types=1);

namespace Wurk\Push;

use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Push\Support\Notifier;

/**
 * Push (Sprint 26.2): the WURK app's notifications on people's phones, with WURK's own VAPID keys. Modules say what
 * happened through Platform's PushNotifications; each person chooses what they're told about, per business.
 */
final class PushServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PushNotifications::class, Notifier::class);
    }

    public function permissions(): array
    {
        return [];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Notifications on my phone', 'route' => 'tenant.push.settings', 'icon' => 'bell', 'section' => 'Settings', 'order' => 805],
        ];
    }
}
