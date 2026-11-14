<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Campaigns\Jobs\SendCampaignBatch;
use Wurk\Campaigns\Mail\CampaignMail;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Crm\Contracts\Audiences;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Platform\Contracts\AudienceSegments;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Campaigns (Sprint 15.3a): email or SMS to customers who agreed to marketing — sent in the background, in batches (18.3a). A credit estimate first (SMS at the
 * business's own rate; email is free); refused if the credits won't cover it. Each message is personalised
 * ({first_name}), its links go through WURK (signed, for click counts), and it carries an opt-out; emails carry the open image.
 */
final readonly class CampaignSender
{
    public function __construct(private TenantContext $context, private Audiences $audiences, private CreditWallet $credits, private AuditLog $audit) {}

    /** @return list<array{id: string, name: string, email: string|null, phone: string|null}> */
    public function recipients(string $channel, string $segment, ?string $branchId): array
    {
        $builtIn = in_array($segment, ['all', 'recent', 'inactive', 'branch'], true);
        $extra = $builtIn ? null : app(AudienceSegments::class)->contacts($segment); // e.g. top referrers (15.3b)
        $people = $builtIn ? $this->audiences->audience($segment, $branchId) : ($extra === null ? [] : $this->audiences->audience('contacts', null, $extra)); // CRM keeps only those who agreed

        return array_values(array_filter($people, fn ($c) => $channel === 'sms' ? $c['phone'] !== null : $c['email'] !== null));
    }

    /** @return array{recipients: int, credits: int, spendable: int|null, enough: bool} credits in whole credits */
    public function estimate(string $channel, string $segment, ?string $branchId): array
    {
        $n = count($this->recipients($channel, $segment, $branchId));
        $milli = $channel === 'sms' ? $n * $this->credits->rateFor('sms') : 0;
        // What can be used: the balance, capped by any monthly spending limit (spendable() is the limit's headroom, null = none).
        $available = $this->credits->balance()['balance_milli'];
        $limit = $this->credits->spendable();
        $available = $limit === null ? $available : min($available, $limit);

        return ['recipients' => $n, 'credits' => (int) ceil($milli / 1000), 'spendable' => intdiv(max(0, $available), 1000), 'enough' => $milli <= $available];
    }

    /** Customers per batch (Sprint 18.3a). */
    public const BATCH = 200;

    /**
     * Start sending (Sprint 18.3a): checked here, sent in the background in batches. Returns how many customers it
     * will go to.
     *
     * @throws \DomainException a plain reason
     */
    public function send(string $campaignId): int
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $c = $db->table('campaigns')->where('tenant_id', $t)->where('id', $campaignId)->whereNull('deleted_at')->first() ?? throw new \DomainException('No such campaign.');
        if (! in_array($c->status, ['draft', 'scheduled'], true)) {
            throw new \DomainException('This campaign has already been sent.');
        }
        $estimate = $this->estimate((string) $c->channel, (string) $c->segment, $c->branch_id);
        if (! $estimate['enough']) {
            throw new \DomainException("You need about {$estimate['credits']} credits for this; you have {$estimate['spendable']}. Top up, or choose fewer customers.");
        }
        $started = $db->table('campaigns')->where('tenant_id', $t)->where('id', $campaignId)->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'sending', 'updated_at' => now()]);
        if ($started === 0) {
            throw new \DomainException('This campaign has already been sent.');
        }
        SendCampaignBatch::dispatch($t, $campaignId, true);

        return $estimate['recipients'];
    }

    /** The first step: who it goes to, each with their own tracking token, waiting to be sent. Returns true. */
    public function prepare(string $campaignId): bool
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $c = $db->table('campaigns')->where('tenant_id', $t)->where('id', $campaignId)->where('status', 'sending')->first();
        if ($c === null || $db->table('campaign_messages')->where('tenant_id', $t)->where('campaign_id', $campaignId)->exists()) {
            return $c !== null; // already listed (a retry): carry on sending
        }
        $now = now();
        foreach (array_chunk($this->recipients((string) $c->channel, (string) $c->segment, $c->branch_id), 500) as $chunk) {
            $db->table('campaign_messages')->insert(array_map(fn ($r) => ['id' => strtolower((string) Str::ulid()), 'tenant_id' => $t, 'campaign_id' => $campaignId,
                'contact_id' => $r['id'], 'token' => Str::lower(Str::random(32)), 'status' => 'queued', 'error' => null, 'created_at' => $now], $chunk));
        }

        return true;
    }

    /** The next batch. Returns true while there's more to send; the last batch marks the campaign sent. */
    public function sendBatch(string $campaignId): bool
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $c = $db->table('campaigns')->where('tenant_id', $t)->where('id', $campaignId)->where('status', 'sending')->first();
        if ($c === null) {
            return false;
        }
        $batch = $db->table('campaign_messages')->where('tenant_id', $t)->where('campaign_id', $campaignId)->where('status', 'queued')->orderBy('id')->limit(self::BATCH)->get();
        // Details now, not when it was listed: someone who withdrew consent since is skipped (POPIA).
        $people = collect($this->audiences->audience('contacts', null, array_values($batch->pluck('contact_id')->map(fn ($id) => (string) $id)->all())))->keyBy('id');
        foreach ($batch as $m) {
            $r = $people->get((string) $m->contact_id);
            $usable = $r !== null && ($c->channel === 'sms' ? $r['phone'] !== null : $r['email'] !== null);
            $error = $usable ? $this->deliver($c, $r, (string) $m->token) : 'No longer agreed to marketing, or no '.($c->channel === 'sms' ? 'phone number' : 'email address');
            $db->table('campaign_messages')->where('tenant_id', $t)->where('id', $m->id)
                ->update(['status' => $error === null ? 'sent' : 'failed', 'error' => $error === null ? null : mb_substr($error, 0, 200), 'created_at' => now()]);
        }
        if ($db->table('campaign_messages')->where('tenant_id', $t)->where('campaign_id', $campaignId)->where('status', 'queued')->exists()) {
            return true;
        }
        $sent = $db->table('campaign_messages')->where('tenant_id', $t)->where('campaign_id', $campaignId)->where('status', 'sent')->count();
        $db->table('campaigns')->where('tenant_id', $t)->where('id', $campaignId)->update(['status' => 'sent', 'sent_at' => now(), 'recipients' => $sent, 'updated_at' => now()]);
        $this->audit->record('campaigns.sent', 'campaign', $campaignId, ['channel' => $c->channel, 'sent' => $sent]);

        return false;
    }

    /** A test to the person composing it — "[Test]", no tracking, nothing recorded against customers. */
    public function test(string $campaignId, string $to, string $name = 'Test'): void
    {
        $c = DB::connection($this->context->connectionName())->table('campaigns')->where('tenant_id', $this->context->id())->where('id', $campaignId)->whereNull('deleted_at')->first() ?? throw new \DomainException('No such campaign.');
        $error = $this->deliver($c, ['id' => 'test', 'name' => $name, 'email' => $to, 'phone' => $to], 'test', true); // reads as a customer would see it
        if ($error !== null) {
            throw new \DomainException('The test didn’t send: '.$error);
        }
    }

    /** @param array{id: string, name: string, email: string|null, phone: string|null} $r */
    private function deliver(\stdClass $c, array $r, string $token, bool $test = false): ?string
    {
        $first = explode(' ', trim($r['name']))[0] ?: 'there';
        $text = str_replace('{first_name}', $first, (string) $c->body);
        $site = $this->site();
        $stop = $site.'/c/'.$token.'/stop';
        $text = (string) preg_replace_callback('#https?://[^\s<>"]+#i', fn ($m) => $test ? $m[0] : $site.'/c/'.$token.'/l?u='.rawurlencode($m[0]).'&s='.$this->sign($token, $m[0]), $text);
        $business = $this->context->current()->displayName();
        try {
            if ($c->channel === 'sms') {
                $result = app(Messenger::class)->sms((string) $r['phone'], ($test ? '[Test] ' : '').$text.' Stop: '.$stop, 'campaign'); // charges credits

                return $result->ok ? null : (string) $result->error;
            }
            $html = nl2br(e($text));
            $html = (string) preg_replace('#(https?://[^\s<]+)#i', '<a href="$1">$1</a>', $html);
            Mail::to((string) $r['email'])->send(new CampaignMail(($test ? '[Test] ' : '').str_replace('{first_name}', $first, (string) $c->subject), $text, $html, $business, $stop, $site.'/c/'.$token.'/o.gif'));

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public function sign(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', $token.'|'.$url, 'campaign-link|'.(string) config('app.key')), 0, 24);
    }

    private function site(): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.$this->context->current()->handle().'.'.config('wurk.domains.root');
    }
}
