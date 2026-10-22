<?php

declare(strict_types=1);

namespace Wurk\Plans\Console;

use Illuminate\Console\Command;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Models\Package;

/**
 * Development and testing only: three example packages so signup and pricing
 * have something to show until WURK sets the real line-up in the admin.
 */
final class SeedPlaceholderPackagesCommand extends Command
{
    protected $signature = 'packages:seed-placeholders';

    protected $description = 'Create example packages (Starter, Growth, Pro) if none exist — not for production';

    public function handle(PackageCatalog $catalog): int
    {
        if (app()->environment('production')) {
            $this->components->error('Placeholder packages are for development and testing only.');

            return self::FAILURE;
        }
        if (Package::query()->exists()) {
            $this->components->info('Packages already exist; nothing to do.');

            return self::SUCCESS;
        }

        $base = ['type' => 'self_managed', 'visibility' => 'public', 'multi_branch' => false, 'max_branches' => 1, 'referral_max_levels' => 7, 'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null];
        foreach ([
            ['key' => 'starter', 'name' => 'Starter', 'description' => 'Website and online bookings for a new business.', 'display_order' => 10, 'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 2, 'custom_domain' => false, 'monthly_credits' => 50, 'modules' => ['website', 'bookings']],
            ['key' => 'growth', 'name' => 'Growth', 'description' => 'Add an online shop, CRM and a referral programme.', 'display_order' => 20, 'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 5120, 'staff_limit' => 10, 'custom_domain' => true, 'monthly_credits' => 200, 'modules' => ['website', 'bookings', 'shop', 'crm', 'referrals']],
            ['key' => 'pro', 'name' => 'Pro', 'description' => 'Several branches, campaigns, reports and the AI assistant.', 'display_order' => 30, 'monthly_price_cents' => 119900, 'yearly_price_cents' => 1199000, 'trial_days' => 14, 'storage_mb' => 20480, 'staff_limit' => 50, 'custom_domain' => true, 'monthly_credits' => 600, 'modules' => ['website', 'bookings', 'shop', 'crm', 'referrals', 'multi-branch', 'marketing', 'reports', 'ai-assistant'], 'multi_branch' => true, 'max_branches' => 5],
        ] as $package) {
            $catalog->create([...$base, ...$package], null);
        }

        $this->components->info('Created Starter, Growth and Pro (example prices — set real ones in the admin).');

        return self::SUCCESS;
    }
}
