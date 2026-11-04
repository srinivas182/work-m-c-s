<?php

declare(strict_types=1);

namespace Wurk\Referrals;

use Illuminate\Support\Facades\Event;
use Wurk\Crm\Contracts\ContactRemembered;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Support\Attribution;
use Wurk\Referrals\Support\Programme;

final class ReferralsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(ReferralRules::class, Programme::class);
    }

    public function boot(): void
    {
        parent::boot();
        // A customer remembered (booking, order, form, chat, sale): record who referred them, from the referral cookie.
        Event::listen(ContactRemembered::class, fn (ContactRemembered $e) => app(Attribution::class)->attach($e->contactId, app(Attribution::class)->codeFromRequest()));
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
