<?php

declare(strict_types=1);

namespace Wurk\Credits\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Credits\Models\CreditBalance;
use Wurk\Credits\Models\CreditLedgerEntry;
use Wurk\Credits\Models\CreditRate;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\CreditPricing;
use Wurk\Plans\Contracts\UsageMeter;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

final class CreditsController
{
    public const CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp message', 'email' => 'Email', 'ai' => 'AI action', 'ai.generate_site' => 'AI: write a website', 'ai.rewrite' => 'AI: rewrite a section', 'ai.image' => 'AI: generate an image'];

    public function __construct(private readonly CreditWallet $wallet, private readonly TenantContext $context) {}

    /** WURK staff: current rate per country and channel, with history. */
    public function rates(Countries $countries): Response
    {
        Gate::authorize('credits.manage');
        $rates = CreditRate::query()->orderByDesc('effective_from')->orderByDesc('id')->get();

        return Inertia::render('Credits::Admin/Rates', [
            'channels' => self::CHANNELS,
            'countries' => array_map(fn (array $c) => [
                'code' => $c['code'],
                'name' => $c['name'],
                'rates' => collect(self::CHANNELS)->map(fn ($label, $channel) => ($r = $rates->first(fn ($r) => $r->country === $c['code'] && $r->channel === $channel && $r->effective_from->lte(now())))
                    ? $r->credits_milli / 1000 : null)->all(),
            ], $countries->enabled()),
            'history' => $rates->take(20)->map(fn (CreditRate $r) => ['country' => $r->country, 'channel' => $r->channel, 'credits' => $r->credits_milli / 1000, 'from' => $r->effective_from->toIso8601String()])->values()->all(),
        ]);
    }

    public function setRate(Request $request): RedirectResponse
    {
        Gate::authorize('credits.manage');
        $data = $request->validate([
            'country' => ['required', 'exists:countries,code'],
            'channel' => ['required', 'in:'.implode(',', array_keys(self::CHANNELS))],
            'credits' => ['required', 'numeric', 'between:0,1000'],
            'effective_from' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        CreditRate::query()->create([
            'country' => $data['country'], 'channel' => $data['channel'], 'credits_milli' => (int) round((float) $data['credits'] * 1000),
            'effective_from' => $data['effective_from'] ?? now(), 'created_by' => (string) $request->user()?->getAuthIdentifier(),
        ]);
        app(AuditLog::class)->record('credits.rate_set', 'credit_rate', $data['country'].':'.$data['channel'], $data, ['platform' => true]);

        return back()->with('status', 'Rate saved. Past usage keeps the rate it was charged at.');
    }

    /** WURK staff: a business's balance, ledger and manual adjustments. */
    public function business(string $tenant, TenantDirectory $directory): Response
    {
        Gate::authorize('credits.view');
        $business = $directory->find($tenant) ?? abort(404);

        return $this->context->run($business, fn () => Inertia::render('Credits::Admin/Business', [
            'business' => ['id' => $business->tenantId(), 'name' => $business->displayName()],
            'canAdjust' => Gate::allows('credits.manage'),
            ...$this->summary(),
        ]));
    }

    public function adjust(Request $request, string $tenant, TenantDirectory $directory): RedirectResponse
    {
        Gate::authorize('credits.manage');
        $business = $directory->find($tenant) ?? abort(404);
        $data = $request->validate(['credits' => ['required', 'numeric', 'not_in:0', 'between:-100000,100000'], 'reason' => ['required', 'string', 'min:5', 'max:200']]);

        try {
            $this->context->run($business, fn () => $this->wallet->grant('adjustment', (int) round((float) $data['credits'] * 1000), 'adjust:'.Str::ulid(), $data['reason'], (string) $request->user()?->getAuthIdentifier()));
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['credits' => $e->getMessage()]);
        }

        return back()->with('status', 'Balance adjusted.');
    }

    /** Business owners: Settings → WURK Credits. */
    public function mine(): Response
    {
        return Inertia::render('Credits::Business', $this->summary());
    }

    public function threshold(Request $request): RedirectResponse
    {
        $data = $request->validate(['low_balance' => ['required', 'integer', 'between:0,100000']]);
        CreditBalance::query()->insertOrIgnore(['tenant_id' => $this->context->id(), 'balance_milli' => 0, 'allowance_milli' => 0, 'low_balance_milli' => 20000, 'updated_at' => now()]);
        CreditBalance::query()->whereKey($this->context->id())->update(['low_balance_milli' => $data['low_balance'] * 1000, 'low_alerted_at' => null]);

        return back()->with('status', 'Alert level saved.');
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $balance = $this->wallet->balance();
        $since = now()->startOfMonth();

        $tenantId = $this->context->id();
        $pricing = app()->bound(CreditPricing::class) ? app(CreditPricing::class) : null;
        $sub = app(Catalog::class)->subscriptionOf($tenantId);
        $limits = $this->wallet->limits();

        return [
            'balance' => $balance['balance_milli'] / 1000,
            'allowance' => $balance['allowance_milli'] / 1000,
            'lowBalance' => $balance['low_balance_milli'] / 1000,
            'randPerCredit' => $pricing?->randPerCredit($tenantId),
            'nextPayment' => $pricing?->nextPayment($tenantId),
            'allowanceExpires' => $sub['current_period_end'] ?? $sub['trial_ends_at'] ?? null,
            'included' => $sub['monthly_credits'] ?? 0,
            'spentThisMonth' => $this->wallet->spentThisMonth() / 1000,
            'meters' => array_map(fn (UsageMeter $m) => $m->measure($tenantId), iterator_to_array(app()->tagged(UsageMeter::TAG))),
            'limits' => ['monthly' => $limits['monthly_milli'] === null ? null : $limits['monthly_milli'] / 1000],
            'canManage' => Gate::allows('billing.manage'),
            'rates' => collect(self::CHANNELS)->map(fn ($label, $channel) => ['label' => $label, 'credits' => rescue(fn () => $this->wallet->rateFor($channel) / 1000, null, false)])->all(),
            // Net of refunds: a failed, refunded action isn't counted as spent.
            'usage' => collect($this->wallet->usageThisMonth())->map(fn (array $u, string $channel) => [
                'channel' => self::CHANNELS[$channel] ?? $channel, 'messages' => $u['count'], 'credits' => $u['milli'] / 1000,
            ])->values()->all(),
            'ledger' => CreditLedgerEntry::query()->orderByDesc('created_at')->limit(30)->get()
                ->map(fn (CreditLedgerEntry $e) => ['at' => $e->created_at->toIso8601String(), 'type' => $e->type, 'description' => $e->description, 'credits' => $e->amount_milli / 1000, 'balance' => $e->balance_after_milli / 1000])->all(),
        ];
    }

    public function limits(Request $request): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $monthly = $request->validate(['monthly' => ['nullable', 'integer', 'min:1', 'max:1000000']])['monthly'] ?? null;
        $this->wallet->setLimits($monthly === null ? null : (int) $monthly * 1000, $this->wallet->limits()['staff'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', $monthly === null ? 'Monthly spending limit removed.' : "Spending is now limited to {$monthly} credits a month.");
    }

    /** The statement as CSV (this month and the two before). */
    public function statement(): StreamedResponse
    {
        $rows = CreditLedgerEntry::query()->where('created_at', '>=', now()->subMonthsNoOverflow(2)->startOfMonth())->orderBy('created_at')->get();

        return new StreamedResponse(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Could not open the output stream.');
            }
            fputcsv($out, ['Date', 'Type', 'Description', 'Credits', 'Balance after']);
            foreach ($rows as $e) {
                fputcsv($out, [$e->created_at->format('Y-m-d H:i'), $e->type, $e->description, number_format($e->amount_milli / 1000, 3, '.', ''), number_format($e->balance_after_milli / 1000, 3, '.', '')]);
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="wurk-credits-statement.csv"']);
    }
}
