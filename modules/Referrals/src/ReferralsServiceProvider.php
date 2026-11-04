<?php

declare(strict_types=1);

namespace Wurk\Referrals;

use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Support\Programme;

final class ReferralsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(ReferralRules::class, Programme::class);
    }

    public function menu(): array
    {
        return [['label' => 'Referrals', 'route' => 'tenant.referrals.settings', 'icon' => 'gift', 'section' => 'Settings', 'permission' => 'referrals.manage', 'entitlement' => 'referrals', 'order' => 838]];
    }

    public function permissions(): array
    {
        return [['key' => 'referrals.manage', 'label' => 'Set the referral programme’s rules']];
    }
}
