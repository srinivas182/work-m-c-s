<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Contracts\AssistantActionTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * "Ask WURK" (16.2): create a coupon ("a 10% December coupon"), checked as the Coupons screen checks one.
 * Undo deletes it while nobody has used it.
 */
final readonly class CreateCouponTool implements AssistantActionTool
{
    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function name(): string
    {
        return 'create_coupon';
    }

    public function description(): string
    {
        return 'Create a coupon code for the shop and/or bookings: a percentage or an amount off, optionally only between two dates or for a number of uses.';
    }

    public function parameters(): array
    {
        return [
            'code' => 'letters, numbers, - or _ (e.g. DECEMBER10); make one up if not given',
            'kind' => 'percent or amount',
            'value' => 'the percentage (e.g. 10) or rand amount (e.g. 50)',
            'applies_to' => 'shop, bookings or both (default both)',
            'starts_on' => 'first day, YYYY-MM-DD (optional)',
            'ends_on' => 'last day, YYYY-MM-DD (optional)',
            'min_spend' => 'minimum spend in rand (optional)',
            'max_uses' => 'how many times it can be used in total (optional)',
            'once_per_customer' => 'true or false (optional)',
        ];
    }

    public function permission(): string
    {
        return 'campaigns.manage';
    }

    public function entitlement(): string
    {
        return 'marketing';
    }

    public function preview(array $params): array
    {
        $c = $this->check($params);
        $off = $c['kind'] === 'percent' ? $this->num($c['value']).'% off' : 'R'.number_format($c['value'], 2).' off';
        $changes = ["Code: {$c['code']}", "Discount: {$off}", 'For: '.['shop' => 'the shop', 'bookings' => 'bookings', 'both' => 'the shop and bookings'][$c['applies_to']]];
        $changes[] = 'Valid: '.($c['starts_on'] ?? 'from now').' to '.($c['ends_on'] ?? 'no end date');
        if ($c['min_spend'] > 0) {
            $changes[] = 'Minimum spend: R'.number_format($c['min_spend'], 2);
        }
        if ($c['max_uses'] !== null) {
            $changes[] = "Up to {$c['max_uses']} uses";
        }
        if ($c['once_per_customer']) {
            $changes[] = 'Once per customer';
        }

        return ['summary' => "Create coupon {$c['code']} ({$off})", 'changes' => $changes];
    }

    public function apply(array $params): array
    {
        $c = $this->check($params);
        $tz = $this->context->current()->timezone();
        $stored = (string) config('app.timezone');
        $id = (string) Str::ulid();
        DB::connection($this->context->connectionName())->table('coupons')->insert(['id' => $id, 'tenant_id' => $this->context->id(), 'code' => $c['code'], 'kind' => $c['kind'],
            'value' => (int) round($c['value'] * 100), // basis points, or cents
            'applies_to' => $c['applies_to'], 'min_spend_cents' => (int) round($c['min_spend'] * 100),
            'starts_at' => $c['starts_on'] === null ? null : CarbonImmutable::parse($c['starts_on'], $tz)->startOfDay()->setTimezone($stored),
            'ends_at' => $c['ends_on'] === null ? null : CarbonImmutable::parse($c['ends_on'], $tz)->endOfDay()->setTimezone($stored),
            'max_uses' => $c['max_uses'], 'once_per_customer' => $c['once_per_customer'], 'campaign_id' => null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('campaigns.coupon_created', 'coupon', $id, ['code' => $c['code'], 'via' => 'Ask WURK']);

        return ['done' => "Coupon {$c['code']} is ready.", 'link' => route('tenant.coupons'), 'undo' => ['id' => $id, 'code' => $c['code']]];
    }

    public function undo(array $undo): bool
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $id = (string) ($undo['id'] ?? '');
        if (! $db->table('coupons')->where('tenant_id', $t)->where('id', $id)->exists() || $db->table('coupon_redemptions')->where('tenant_id', $t)->where('coupon_id', $id)->exists()) {
            return false; // gone already, or someone has used it — switch it off on Coupons instead
        }
        $db->table('coupons')->where('tenant_id', $t)->where('id', $id)->delete();
        $this->audit->record('campaigns.coupon_deleted', 'coupon', $id, ['code' => $undo['code'] ?? null, 'via' => 'Ask WURK (undo)']);

        return true;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{code: string, kind: string, value: float, applies_to: string, starts_on: string|null, ends_on: string|null, min_spend: float, max_uses: int|null, once_per_customer: bool}
     */
    private function check(array $p): array
    {
        $code = strtoupper(trim((string) ($p['code'] ?? '')));
        if (preg_match('/^[A-Z0-9_-]{3,40}$/', $code) !== 1) {
            throw new AssistantRefused('A coupon code needs 3–40 letters, numbers, - or _.');
        }
        if (DB::connection($this->context->connectionName())->table('coupons')->where('tenant_id', $this->context->id())->where('code', $code)->exists()) {
            throw new AssistantRefused("You already have a coupon called {$code}. Choose another code.");
        }
        $kind = (string) ($p['kind'] ?? '');
        $value = is_numeric($p['value'] ?? null) ? (float) $p['value'] : 0.0;
        if (! in_array($kind, ['percent', 'amount'], true) || $value <= 0 || ($kind === 'percent' && $value > 100) || $value > 100000) {
            throw new AssistantRefused('How much off — a percentage up to 100, or a rand amount?');
        }
        $applies = (string) ($p['applies_to'] ?? 'both');
        $applies = in_array($applies, ['shop', 'bookings', 'both'], true) ? $applies : 'both';
        $today = CarbonImmutable::now($this->context->current()->timezone())->toDateString();
        $starts = $this->date($p['starts_on'] ?? null);
        $ends = $this->date($p['ends_on'] ?? null);
        if ($ends !== null && $ends < $today) {
            throw new AssistantRefused('The last day has already passed.');
        }
        if ($starts !== null && $ends !== null && $ends < $starts) {
            throw new AssistantRefused('The last day is before the first day.');
        }
        $min = is_numeric($p['min_spend'] ?? null) ? max(0.0, min(100000.0, (float) $p['min_spend'])) : 0.0;
        $max = is_numeric($p['max_uses'] ?? null) && (int) $p['max_uses'] >= 1 ? min(1000000, (int) $p['max_uses']) : null;

        return ['code' => $code, 'kind' => $kind, 'value' => $value, 'applies_to' => $applies, 'starts_on' => $starts, 'ends_on' => $ends,
            'min_spend' => $min, 'max_uses' => $max, 'once_per_customer' => filter_var($p['once_per_customer'] ?? false, FILTER_VALIDATE_BOOL)];
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $v) : null;
        if (! $d instanceof CarbonImmutable || $d->toDateString() !== $v) {
            throw new AssistantRefused('Give dates as YYYY-MM-DD.');
        }

        return $v;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
