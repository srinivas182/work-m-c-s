<?php

declare(strict_types=1);

namespace Wurk\Loyalty\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Platform\Contracts\LoyaltyCards;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Loyalty cards on WhatsApp (Sprint 26.5).
 *
 * - The owner sets the reward ("your 8th haircut free" or "R50 off"), the stamps needed, the minimum spend that
 *   earns a stamp, and what counts: bookings, orders, in-store sales. Included in every package (decision D).
 * - A stamp is added once per visit, order or sale, when what's been paid for it reaches the minimum spend — a
 *   deposit online and the rest in cash count together.
 * - A full card gives one reward, offered at the next booking or checkout and used once. It never adds to another
 *   discount: the customer gets the better of the two.
 * - The customer sees "6 of 8 — 2 more to your free cut" on WhatsApp, on their account page, and in the reply to
 *   "my card". No app.
 */
final readonly class Loyalty implements LoyaltyCards
{
    public const SOURCES = ['booking' => 'booking', 'class' => 'booking', 'order' => 'order', 'sale' => 'sale'];

    public function __construct(private TenantContext $context, private ContactBook $contacts, private AuditLog $audit) {}

    /** @return array{on: bool, reward: string, kind: string, value: int, max_cents: int|null, stamps: int, min_spend_cents: int, counts: list<string>} */
    public function settings(): array
    {
        $r = $this->db()->table('loyalty_settings')->where('tenant_id', $this->context->id())->first();

        return ['on' => (bool) ($r->on ?? false), 'reward' => (string) ($r->reward ?? __('your next visit free')), 'kind' => (string) ($r->kind ?? 'percent'),
            'value' => (int) ($r->value ?? 10000), 'max_cents' => isset($r->max_cents) ? (int) $r->max_cents : null, 'stamps' => (int) ($r->stamps ?? 8),
            'min_spend_cents' => (int) ($r->min_spend_cents ?? 0), 'counts' => array_values((array) json_decode((string) ($r->counts ?? '["booking","order","sale"]'), true))];
    }

    /** @param array{on: bool, reward: string, kind: string, value: int, max_cents: int|null, stamps: int, min_spend_cents: int, counts: list<string>} $s */
    public function save(array $s, string $by): void
    {
        if ($s['stamps'] < 2 || $s['stamps'] > 30) {
            throw ValidationException::withMessages(['stamps' => __('Choose from 2 to 30 stamps.')]);
        }
        if (($s['kind'] === 'percent' && ($s['value'] < 100 || $s['value'] > 10000)) || ($s['kind'] === 'amount' && $s['value'] < 100)) {
            throw ValidationException::withMessages(['value' => $s['kind'] === 'percent' ? __('Give a percentage from 1 to 100.') : __('Give an amount of at least 1.')]);
        }
        if ($s['on'] && $s['counts'] === []) {
            throw ValidationException::withMessages(['counts' => __('Choose what earns a stamp.')]);
        }
        $this->db()->table('loyalty_settings')->updateOrInsert(['tenant_id' => $this->context->id()], ['on' => $s['on'], 'reward' => mb_substr(trim(strip_tags($s['reward'])), 0, 80),
            'kind' => $s['kind'], 'value' => $s['value'], 'max_cents' => $s['max_cents'], 'stamps' => $s['stamps'], 'min_spend_cents' => $s['min_spend_cents'],
            'counts' => json_encode(array_values(array_intersect(['booking', 'order', 'sale'], $s['counts']))), 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('loyalty.settings', 'loyalty', $this->context->id(), ['on' => $s['on'], 'stamps' => $s['stamps'], 'by' => $by]);
    }

    /**
     * A purchase was paid (PurchaseMade): count it towards the customer's stamp. Returns the card's line when a stamp
     * was added, else null.
     */
    public function purchased(string $sourceType, string $sourceId, int $paidCents): ?string
    {
        $s = $this->settings();
        $kind = self::SOURCES[$sourceType] ?? null;
        if (! $s['on'] || $kind === null || ! in_array($kind, $s['counts'], true) || $paidCents <= 0) {
            return null;
        }
        $contact = $this->contacts->contactFor($sourceType, $sourceId);
        if ($contact === null) {
            return null;
        }

        return DB::connection($this->context->connectionName())->transaction(function () use ($s, $contact, $sourceType, $sourceId, $paidCents): ?string {
            $card = $this->card($contact, lock: true);
            $p = $this->db()->table('loyalty_purchases')->where('tenant_id', $this->context->id())->where('source_type', $sourceType)->where('source_id', $sourceId)->lockForUpdate()->first();
            if ($p === null) {
                $this->db()->table('loyalty_purchases')->insert(['tenant_id' => $this->context->id(), 'source_type' => $sourceType, 'source_id' => $sourceId, 'card_id' => $card->id,
                    'paid_cents' => $paidCents, 'stamped' => false, 'created_at' => now(), 'updated_at' => now()]);
                $total = $paidCents;
                $stamped = false;
            } else {
                $total = max((int) $p->paid_cents, $paidCents); // PurchaseMade carries everything paid for it so far
                $stamped = (bool) $p->stamped;
                $this->db()->table('loyalty_purchases')->where('tenant_id', $this->context->id())->where('source_type', $sourceType)->where('source_id', $sourceId)
                    ->update(['paid_cents' => $total, 'updated_at' => now()]);
            }
            if ($stamped || $total < $s['min_spend_cents']) {
                return null; // one stamp per visit; not yet enough spent
            }
            $this->db()->table('loyalty_purchases')->where('tenant_id', $this->context->id())->where('source_type', $sourceType)->where('source_id', $sourceId)->update(['stamped' => true]);
            $stamps = $card->stamps + 1;
            if ($stamps >= $s['stamps']) {
                $stamps -= $s['stamps'];
                $this->db()->table('loyalty_rewards')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'card_id' => $card->id, 'status' => 'ready',
                    'created_at' => now(), 'updated_at' => now()]);
                $this->audit->record('loyalty.reward_earned', 'contact', $contact, ['source' => $sourceType]);
            }
            $this->db()->table('loyalty_cards')->where('tenant_id', $this->context->id())->where('id', $card->id)->update(['stamps' => $stamps, 'updated_at' => now()]);

            return $this->forContact($contact)['line'] ?? null;
        });
    }

    public function forPhone(?string $phone): ?array
    {
        $contact = $phone === null ? null : $this->contacts->find($phone);

        return $contact === null ? null : $this->forContact((string) $contact['id']);
    }

    public function forContact(string $contactId): ?array
    {
        $s = $this->settings();
        if (! $s['on']) {
            return null;
        }
        $card = $this->db()->table('loyalty_cards')->where('tenant_id', $this->context->id())->where('contact_id', $contactId)->first();
        $stamps = (int) ($card->stamps ?? 0);
        $ready = $card === null ? 0 : $this->db()->table('loyalty_rewards')->where('tenant_id', $this->context->id())->where('card_id', $card->id)->where('status', 'ready')->count();

        return ['stamps' => $stamps, 'needed' => $s['stamps'], 'ready' => $ready, 'reward' => $s['reward'], 'line' => self::line($stamps, $s['stamps'], $ready, $s['reward'])];
    }

    /** "6 of 8 — 2 more to your free cut." / "Your reward is ready: your free cut." */
    public static function line(int $stamps, int $needed, int $ready, string $reward): string
    {
        if ($ready > 0) {
            return __('Your reward is ready: :reward. It’s used at your next visit or order.', ['reward' => $reward]);
        }
        $left = max(1, $needed - $stamps);

        return __(':stamps of :needed — :left more to :reward.', ['stamps' => $stamps, 'needed' => $needed, 'left' => $left, 'reward' => $reward]);
    }

    public function offer(?string $phone, int $cents, string $where): ?array
    {
        $s = $this->settings();
        $contact = $phone === null || trim($phone) === '' ? null : $this->contacts->find($phone);
        if (! $s['on'] || $contact === null || $cents <= 0) {
            return null;
        }
        $card = $this->db()->table('loyalty_cards')->where('tenant_id', $this->context->id())->where('contact_id', $contact['id'])->first();
        $reward = $card === null ? null : $this->db()->table('loyalty_rewards')->where('tenant_id', $this->context->id())->where('card_id', $card->id)->where('status', 'ready')->orderBy('created_at')->first();
        if ($reward === null) {
            return null;
        }
        $discount = $s['kind'] === 'amount' ? min($cents, $s['value']) : intdiv($cents * $s['value'] + 5000, 10000);
        if ($s['max_cents'] !== null) {
            $discount = min($discount, $s['max_cents']);
        }

        return $discount <= 0 ? null : ['reward_id' => (string) $reward->id, 'discount_cents' => $discount, 'label' => ucfirst($s['reward'])];
    }

    public function redeem(string $rewardId, string $sourceType, string $sourceId, int $discountCents): bool
    {
        $used = $this->db()->table('loyalty_rewards')->where('tenant_id', $this->context->id())->where('id', $rewardId)->where('status', 'ready')
            ->update(['status' => 'used', 'used_on_type' => $sourceType, 'used_on_id' => $sourceId, 'discount_cents' => $discountCents, 'used_at' => now(), 'updated_at' => now()]) === 1;
        if ($used) {
            $this->audit->record('loyalty.reward_used', 'loyalty_reward', $rewardId, ['on' => $sourceType, 'cents' => $discountCents]);
        }

        return $used;
    }

    public function release(string $sourceType, string $sourceId): void
    {
        $this->db()->table('loyalty_rewards')->where('tenant_id', $this->context->id())->where('used_on_type', $sourceType)->where('used_on_id', $sourceId)->where('status', 'used')
            ->update(['status' => 'ready', 'used_on_type' => null, 'used_on_id' => null, 'discount_cents' => null, 'used_at' => null, 'updated_at' => now()]);
    }

    /** Tells the customer their progress after a stamp: in the chat if they wrote in the last day, else the approved template. */
    public function tell(string $sourceType, string $sourceId, string $line): void
    {
        try {
            $contact = $this->contacts->contactFor($sourceType, $sourceId);
            $profile = $contact === null ? null : $this->contacts->profile($contact);
            $phone = preg_replace('/\D/', '', (string) ($profile['phone'] ?? '')) ?? '';
            $wa = app(WhatsAppBusiness::class);
            if ($phone === '' || ! $wa->connected()) {
                return;
            }
            $first = (string) (preg_split('/\s+/', trim((string) ($profile['name'] ?? '')))[0] ?? '');
            if ($wa->templateReady(WhatsAppBusiness::LOYALTY_PROGRESS)) {
                $wa->sendTemplate($phone, WhatsAppBusiness::LOYALTY_PROGRESS, [$first !== '' ? $first : __('there'), $this->context->current()->displayName(), $line]);
            }
        } catch (Throwable $e) {
            report($e); // the stamp is kept either way
        }
    }

    /** @return object{id: string, stamps: int} */
    private function card(string $contactId, bool $lock = false): object
    {
        $q = $this->db()->table('loyalty_cards')->where('tenant_id', $this->context->id())->where('contact_id', $contactId);
        $card = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($card === null) {
            $this->db()->table('loyalty_cards')->insertOrIgnore(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'contact_id' => $contactId, 'stamps' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            $card = $this->db()->table('loyalty_cards')->where('tenant_id', $this->context->id())->where('contact_id', $contactId)->first();
        }

        if ($card === null) {
            throw new \RuntimeException('No loyalty card.');
        }

        return (object) ['id' => (string) $card->id, 'stamps' => (int) $card->stamps];
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->context->connectionName());
    }
}
