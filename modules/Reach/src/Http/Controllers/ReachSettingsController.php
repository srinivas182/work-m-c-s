<?php

declare(strict_types=1);

namespace Wurk\Reach\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Reach\Models\ReachNumber;
use Wurk\Reach\Models\UssdSession;
use Wurk\Reach\Support\ReachNumbers;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Settings → Bookings by phone (25.2b): the business's USSD number and SMS word, how customers use them, switches,
 * and a counter card to print.
 */
final class ReachSettingsController
{
    /** Words that can't be a business's SMS word (they mean something to the SMS menu or the network). */
    public const RESERVED = ['BOOK', 'STOP', 'HELP', 'YES', 'NO', 'START', 'END', 'CANCEL', 'INFO', 'WURK'];

    public function __construct(private TenantContext $context, private ReachNumbers $numbers, private ProviderCredentials $credentials, private AuditLog $audit) {}

    public function index(): Response
    {
        $number = $this->numbers->for($this->context->id());

        return Inertia::render('Reach::Settings', [
            'reach' => ['number' => $number->number, 'ussd_on' => $number->ussd_on, 'sms_on' => $number->sms_on, 'sms_keyword' => $number->sms_keyword],
            'how' => $this->how($number),
            'live' => ['ussd' => $this->dial($number) !== null, 'sms' => $this->smsNumber() !== null],
            'month' => [
                'ussd' => UssdSession::query()->where('tenant_id', $this->context->id())->where('provider', '!=', 'sms')->where('created_at', '>=', now()->startOfMonth())->count(),
                'sms' => UssdSession::query()->where('tenant_id', $this->context->id())->where('provider', 'sms')->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $number = $this->numbers->for($this->context->id());
        $data = $request->validate([
            'ussd_on' => ['required', 'boolean'],
            'sms_on' => ['required', 'boolean'],
            'sms_keyword' => ['nullable', 'string', 'regex:/^[A-Za-z][A-Za-z0-9]{2,19}$/', Rule::notIn(array_merge(self::RESERVED, array_map('strtolower', self::RESERVED))),
                Rule::unique('reach_numbers', 'sms_keyword')->ignore($number->tenant_id, 'tenant_id')],
        ], [
            'sms_keyword.regex' => __('Use 3 to 20 letters and digits, starting with a letter.'),
            'sms_keyword.not_in' => __('That word is used by the SMS menu. Choose another.'),
            'sms_keyword.unique' => __('Another business uses that word. Choose another.'),
        ]);
        $keyword = $data['sms_keyword'] === null || $data['sms_keyword'] === '' ? null : strtoupper((string) $data['sms_keyword']);
        if ($keyword !== null && ReachNumber::query()->where('sms_keyword', $keyword)->where('tenant_id', '!=', $number->tenant_id)->exists()) {
            return back()->withErrors(['sms_keyword' => __('Another business uses that word. Choose another.')]);
        }
        $before = $number->only(['ussd_on', 'sms_on', 'sms_keyword']);
        $number->update(['ussd_on' => (bool) $data['ussd_on'], 'sms_on' => (bool) $data['sms_on'], 'sms_keyword' => $keyword]);
        $this->audit->record('reach.updated', 'reach', $number->tenant_id, ['before' => $before, 'after' => $number->only(['ussd_on', 'sms_on', 'sms_keyword'])]);

        return back()->with('status', __('Saved.'));
    }

    /** A card for the counter (A6, four to a page): how to book from any phone. */
    public function card(): View
    {
        $number = $this->numbers->for($this->context->id());

        return view('reach::card', ['business' => $this->context->current()->displayName(), 'how' => $this->how($number)]);
    }

    /** @return array{dial: string|null, sms: string|null} */
    private function how(ReachNumber $number): array
    {
        $sms = $this->smsNumber();

        return [
            'dial' => $number->ussd_on ? $this->dial($number) : null,
            'sms' => $number->sms_on && $sms !== null ? (string) __('Text :word to :number', ['word' => $number->sms_keyword ?? 'BOOK '.$number->number, 'number' => $sms]) : null,
        ];
    }

    /** WURK's code with the business's number in it: *384*1234# → *384*1234*5678#. */
    private function dial(ReachNumber $number): ?string
    {
        $code = (string) ($this->credentials->active('ussd')['settings']['service_code'] ?? '');

        return preg_match('/^\*[\d*]+#$/', $code) === 1 ? substr($code, 0, -1).'*'.$number->number.'#' : null;
    }

    private function smsNumber(): ?string
    {
        $n = trim((string) ($this->credentials->active('ussd')['settings']['sms_number'] ?? ''));

        return $n === '' ? null : $n;
    }
}
