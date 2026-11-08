<?php

declare(strict_types=1);

namespace Wurk\Referrals\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\RefundWindow;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Programme;
use Wurk\Referrals\Support\Wallets;

/** Settings → Referrals: the programme's rules, within the package's guardrails. */
final class ReferralSettingsController
{
    public function __construct(private Programme $programme, private AuditLog $audit) {}

    public function show(): Response
    {
        return Inertia::render('Referrals::Settings', ['rules' => $this->programme->rules(), 'limits' => $this->programme->limits(), 'returnDays' => app(RefundWindow::class)->days(), 'wallet' => app(Wallets::class)->rules()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $limits = $this->programme->limits();
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'self_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'level_bp' => ['present', 'array', 'max:'.$limits['max_levels']],
            'level_bp.*' => ['required', 'integer', 'min:0', 'max:10000'],
            'attribution' => ['required', 'in:first,last'],
            'link_days' => ['required', 'integer', 'min:1', 'max:365'],
        ], [
            'level_bp.max' => 'Your package allows up to '.$limits['max_levels'].' '.($limits['max_levels'] === 1 ? 'level' : 'levels').'.',
            'link_days.min' => 'A link should count for at least a day.', 'link_days.max' => 'A link can count for up to a year (365 days).',
        ]);
        $total = (int) $d['self_bp'] + array_sum(array_map('intval', $d['level_bp']));
        if ($total > $limits['max_bp']) {
            throw ValidationException::withMessages(['total' => 'Together these come to '.self::pct($total).'; your package allows up to '.self::pct($limits['max_bp']).' in total.']);
        }
        if ($d['enabled'] && $total === 0) {
            throw ValidationException::withMessages(['total' => 'Set at least one percentage before switching the programme on.']);
        }
        ReferralProgramme::query()->updateOrCreate([], ['enabled' => (bool) $d['enabled'], 'self_bp' => (int) $d['self_bp'], 'level_bp' => array_values(array_map('intval', $d['level_bp'])),
            'attribution' => $d['attribution'], 'link_days' => (int) $d['link_days']]);
        $this->audit->record('referrals.rules', 'referral_programme', 'rules', ['enabled' => (bool) $d['enabled'], 'self_bp' => (int) $d['self_bp'], 'level_bp' => $d['level_bp'],
            'attribution' => $d['attribution'], 'link_days' => (int) $d['link_days']]);

        return back()->with('status', $d['enabled'] ? 'Referral programme saved — it’s on.' : 'Referral programme saved — it’s off.');
    }

    private static function pct(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%';
    }

    /** Wallet rules: payouts, the minimum payout, the most of an order a wallet may pay, expiry. */
    public function wallet(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'payouts_allowed' => ['required', 'boolean'],
            'min_payout_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max_wallet_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'expiry_months' => ['required', 'integer', 'min:0', 'max:60'],
        ], ['max_wallet_bp.max' => 'A wallet can pay at most the whole order (100%).', 'expiry_months.max' => 'Up to 5 years (60 months); 0 means rewards never expire.']);
        ReferralProgramme::query()->firstOrCreate([], ['level_bp' => []])->update(['payouts_allowed' => (bool) $d['payouts_allowed'], 'min_payout_cents' => (int) $d['min_payout_cents'],
            'max_wallet_bp' => (int) $d['max_wallet_bp'], 'expiry_months' => (int) $d['expiry_months']]);
        $this->audit->record('referrals.wallet_rules', 'referral_programme', 'wallet', $d);

        return back()->with('status', 'Wallet rules saved.');
    }
}
