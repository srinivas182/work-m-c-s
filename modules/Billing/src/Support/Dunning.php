<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Wurk\Billing\Mail\DunningNoticeMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Where a business is in dunning (PRD §11.1), and telling the owner. */
final readonly class Dunning
{
    public function __construct(private Catalog $catalog, private BillingSettings $settings, private TenantDirectory $tenants, private Messenger $messenger) {}

    /** Days since the payment failed (day 0 = the failure), or null when not in dunning. */
    public function day(string $tenantId): ?int
    {
        $sub = $this->catalog->subscriptionOf($tenantId);
        if ($sub === null || ! in_array($sub['status'], ['past_due', 'suspended'], true) || $sub['past_due_since'] === null) {
            return null;
        }

        return (int) floor(Carbon::parse($sub['past_due_since'])->diffInDays(now(), true));
    }

    /** @return 'none'|'grace'|'restricted'|'suspended' */
    public function stage(string $tenantId): string
    {
        $day = $this->day($tenantId);
        if ($day === null) {
            return 'none';
        }
        $s = $this->settings->dunning();
        if (($this->catalog->subscriptionOf($tenantId)['status'] ?? '') === 'suspended' || $day >= $s['suspend_on_day']) {
            return 'suspended';
        }

        return $day >= $s['restrict_from_day'] ? 'restricted' : 'grace';
    }

    /** @param  'failed'|'reminder'|'suspended'  $kind */
    public function notify(string $tenantId, string $kind): void
    {
        $account = BillingAccount::query()->find($tenantId);
        $tenant = $this->tenants->find($tenantId);
        if ($account === null || $tenant === null) {
            return;
        }

        $payUrl = 'https://'.config('wurk.domains.app').'/'.$tenant->handle().'/settings/billing';
        $s = $this->settings->dunning();
        Mail::to($account->billing_email)->send(new DunningNoticeMail($kind, $tenant->displayName(), $payUrl, $s['restrict_from_day'], $s['suspend_on_day']));

        if ($account->billing_phone !== null) {
            $text = match ($kind) {
                'suspended' => "WURK: {$tenant->displayName()} is suspended for non-payment. Pay to restore it at once: {$payUrl}",
                default => "WURK: we couldn’t take payment for {$tenant->displayName()}. Please pay here to avoid interruption: {$payUrl}",
            };
            $this->messenger->sms($account->billing_phone, $text, 'dunning_'.$kind);
        }
    }
}
