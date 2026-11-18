<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Messaging\Drivers\WhatsApp\MetaCloudWhatsApp;
use Wurk\Platform\Contracts\OwnerDigest;
use Wurk\Platform\Contracts\OwnerDigestRefused;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * The owner's evening WhatsApp summary (Sprint 21.6): tomorrow's bookings, today's orders and money in, chats waiting,
 * and up to five requests needing a yes or no. It comes from WURK's own number (an approved template), to the owner's
 * verified mobile only, charged to the business in credits. The owner replies "1" to say yes to request 1, or "N1" to
 * say no; the reply is matched to the owner by that same verified number, applied as them, audited, and answered.
 */
final readonly class OwnerSummaries
{
    /** The template on WURK's own WhatsApp account (HQ submits it — docs/deployment/docker.md). */
    public const TEMPLATE = 'wurk_owner_summary';

    /** Requests in one summary (one digit to reply). */
    public const REQUESTS = 5;

    /** How long the numbers in a summary can be answered. */
    public const ANSWER_HOURS = 24;

    public const HELP = 'Reply with a number from your summary to say yes (e.g. 1), or N and the number to say no (e.g. N1).';

    public function __construct(
        private TenantContext $context,
        private TenantDirectory $tenants,
        private TeamDirectory $team,
        private PeopleDirectory $people,
        private OwnerDigest $digest,
        private AuditLog $audit,
        private ProviderCredentials $providers,
    ) {}

    /**
     * The current business's setting for this person (an owner), and where it would go.
     *
     * @return array{enabled: bool, send_at: string, phone: string|null}
     */
    public function settings(string $identityId): array
    {
        $row = DB::table('whatsapp_owner_summaries')->where('tenant_id', $this->context->id())->where('identity_id', $identityId)->first();

        return ['enabled' => (bool) ($row->enabled ?? false), 'send_at' => (string) ($row->send_at ?? '19:00'), 'phone' => $this->people->verifiedPhone($identityId)];
    }

    public function save(string $identityId, bool $enabled, string $sendAt): void
    {
        $where = ['tenant_id' => $this->context->id(), 'identity_id' => $identityId];
        DB::table('whatsapp_owner_summaries')->where($where)->exists()
            ? DB::table('whatsapp_owner_summaries')->where($where)->update(['enabled' => $enabled, 'send_at' => $sendAt, 'updated_at' => now()])
            : DB::table('whatsapp_owner_summaries')->insert([...$where, 'id' => (string) Str::ulid(), 'enabled' => $enabled, 'send_at' => $sendAt, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Whether this person is an active owner of the current business. */
    public function isOwner(string $identityId): bool
    {
        return collect($this->team->roster())->contains(fn (array $m) => $m['identity_id'] === $identityId && $m['role'] === 'owner' && $m['active']);
    }

    /** Sends the summaries that are due (every 15 minutes, across businesses). */
    public function sendDue(): int
    {
        $sent = 0;
        foreach (DB::table('whatsapp_owner_summaries')->where('enabled', true)->get() as $row) {
            $tenant = $this->tenants->find((string) $row->tenant_id);
            if ($tenant === null) {
                continue;
            }
            $now = CarbonImmutable::now($tenant->timezone());
            if ($row->last_sent_on === $now->toDateString() || $now->format('H:i') < (string) $row->send_at) {
                continue;
            }
            try {
                $sent += (int) $this->context->run($tenant, fn () => $this->send((string) $row->identity_id));
            } catch (Throwable $e) {
                report($e);
            }
            DB::table('whatsapp_owner_summaries')->where('id', $row->id)->update(['last_sent_on' => $now->toDateString(), 'updated_at' => now()]);
        }

        return $sent;
    }

    /** Builds and sends the current business's summary to this owner. Returns whether it was sent. */
    public function send(string $identityId): bool
    {
        $phone = $this->people->verifiedPhone($identityId);
        if ($phone === null || ! $this->isOwner($identityId)) {
            return false; // only an owner, and only to a number they've proved is theirs
        }
        $tenant = $this->context->current();
        $today = CarbonImmutable::now($tenant->timezone())->startOfDay();
        $figures = array_fill_keys(['tomorrow_bookings', 'new_orders', 'received_cents', 'chats_waiting'], 0);
        $requests = [];
        foreach ($this->digest->sources() as $source) {
            foreach ($source->figures($today) as $key => $value) {
                $figures[$key] = ($figures[$key] ?? 0) + $value;
            }
            foreach ($source->requests() as $r) {
                $requests[] = ['source' => $source->key(), ...$r];
            }
        }
        $requests = array_slice($requests, 0, self::REQUESTS);
        $digits = (string) preg_replace('/\D/', '', $phone);
        DB::table('whatsapp_owner_actions')->where('tenant_id', $tenant->tenantId())->where('identity_id', $identityId)->whereNull('done_at')->delete(); // earlier numbers stop working
        foreach ($requests as $i => $r) {
            DB::table('whatsapp_owner_actions')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->tenantId(), 'identity_id' => $identityId, 'phone' => $digits,
                'number' => $i + 1, 'source' => $r['source'], 'ref' => $r['id'], 'label' => mb_substr($r['label'], 0, 200),
                'expires_at' => now()->addHours(self::ANSWER_HOURS), 'created_at' => now()]);
        }
        $asks = $requests === [] ? 'Nothing needs your answer.'
            : 'Needs your yes or no: '.implode(' · ', array_map(fn (array $r, int $i) => ($i + 1).') '.$r['label'], $requests, array_keys($requests))).' Reply with the number to say yes, or N and the number to say no (e.g. N1).';
        $name = (string) strtok($this->people->names([$identityId])[$identityId] ?? '', ' ');
        $result = app(Messenger::class)->whatsappTemplate($phone, self::TEMPLATE, [$name !== '' ? $name : 'there', $tenant->displayName(),
            (string) $figures['tomorrow_bookings'], (string) $figures['new_orders'], 'R'.number_format($figures['received_cents'] / 100, 2, '.', ' '), (string) $figures['chats_waiting'], $asks], 'owner_summary');
        $this->audit->record('messaging.owner_summary_sent', 'whatsapp_owner_summary', $identityId, ['sent' => $result->ok, 'requests' => count($requests)]);

        return $result->ok;
    }

    /**
     * An owner's reply to WURK's number ("1", "N2", "yes 3"). Returns the answer to send back, or null when the number
     * isn't an owner's with a recent summary (other messages to WURK's number aren't for this).
     */
    public function reply(string $from, string $text): ?string
    {
        $from = (string) preg_replace('/\D/', '', $from);
        $open = DB::table('whatsapp_owner_actions')->where('phone', $from)->where('expires_at', '>', now())->orderByDesc('created_at')->get();
        if ($open->isEmpty()) {
            return null;
        }
        if (preg_match('/^\s*(?:(n|no|decline)\s*)?(?:(y|yes|ok)\s*)?#?\s*(\d)\s*$/i', $text, $m) !== 1) {
            return self::HELP;
        }
        $yes = $m[1] === '';
        $action = $open->first(fn ($a) => (int) $a->number === (int) $m[3]);
        if ($action === null) {
            return 'There’s no number '.$m[3].' in your latest summary. '.self::HELP;
        }
        if ($action->done_at !== null) {
            return 'You’ve already answered '.$m[3].': '.$action->outcome;
        }
        $tenant = $this->tenants->find((string) $action->tenant_id);
        if ($tenant === null) {
            return null;
        }

        return (string) $this->context->run($tenant, function () use ($action, $yes, $from): string {
            $identity = (string) $action->identity_id;
            // Still an owner, still this verified number.
            if (! $this->isOwner($identity) || (string) preg_replace('/\D/', '', (string) $this->people->verifiedPhone($identity)) !== $from) {
                return 'Sorry, this number can’t answer for '.$this->context->current()->displayName().' any more.';
            }
            $source = collect($this->digest->sources())->first(fn ($s) => $s->key() === $action->source);
            $name = $this->people->names([$identity])[$identity] ?? 'Owner';
            try {
                $outcome = $source === null ? throw new OwnerDigestRefused('That can’t be answered here any more.') : $source->decide((string) $action->ref, $yes, $identity, $name);
            } catch (OwnerDigestRefused $e) {
                $outcome = $e->getMessage();
            }
            DB::table('whatsapp_owner_actions')->where('id', $action->id)->update(['done_at' => now(), 'outcome' => mb_substr($outcome, 0, 200)]);
            $this->audit->record('messaging.owner_whatsapp_answer', $action->source, (string) $action->ref, ['yes' => $yes, 'by' => $identity, 'via' => 'whatsapp', 'outcome' => $outcome]);

            return $outcome;
        });
    }

    /** Sends the answer from WURK's own number (the owner just wrote, so the 24-hour window is open). */
    public function answer(string $to, string $text): void
    {
        $provider = $this->providers->active('whatsapp');
        if ($provider !== null) {
            app(MetaCloudWhatsApp::class)->sendText($to, $text, $provider['credentials'], $provider['settings']);
        }
    }
}
