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
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Credits\Models\CreditBalance;
use Wurk\Credits\Models\CreditLedgerEntry;
use Wurk\Credits\Models\CreditRate;
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

        return [
            'balance' => $balance['balance_milli'] / 1000,
            'allowance' => $balance['allowance_milli'] / 1000,
            'lowBalance' => $balance['low_balance_milli'] / 1000,
            'rates' => collect(self::CHANNELS)->map(fn ($label, $channel) => ['label' => $label, 'credits' => rescue(fn () => $this->wallet->rateFor($channel) / 1000, null, false)])->all(),
            'usage' => CreditLedgerEntry::query()->where('type', 'debit')->where('created_at', '>=', $since)
                ->selectRaw('channel, count(*) as messages, sum(amount_milli) as total')->groupBy('channel')->get()
                ->map(fn (CreditLedgerEntry $r) => [
                    'channel' => self::CHANNELS[(string) $r->channel] ?? $r->channel,
                    'messages' => (int) $r->getAttribute('messages'),
                    'credits' => abs((int) $r->getAttribute('total')) / 1000,
                ])->all(),
            'ledger' => CreditLedgerEntry::query()->orderByDesc('created_at')->limit(30)->get()
                ->map(fn (CreditLedgerEntry $e) => ['at' => $e->created_at->toIso8601String(), 'type' => $e->type, 'description' => $e->description, 'credits' => $e->amount_milli / 1000, 'balance' => $e->balance_after_milli / 1000])->all(),
        ];
    }
}
