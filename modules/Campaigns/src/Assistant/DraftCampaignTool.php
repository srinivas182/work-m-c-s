<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Campaigns\Http\Controllers\CampaignsController;
use Wurk\Campaigns\Support\CampaignSender;
use Wurk\Platform\Contracts\AssistantActionTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Platform\Contracts\AudienceSegments;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * "Ask WURK" (16.2): draft an email or SMS campaign ("draft a win-back campaign"). It is only saved as a draft — the
 * owner tests and sends it from Campaigns as usual. Undo deletes the draft while it hasn't been sent or scheduled.
 */
final readonly class DraftCampaignTool implements AssistantActionTool
{
    public function __construct(private TenantContext $context, private CampaignSender $sender, private AudienceSegments $audiences, private AuditLog $audit) {}

    public function name(): string
    {
        return 'draft_campaign';
    }

    public function description(): string
    {
        return 'Save a draft email or SMS campaign for the owner to check, test and send later (it is never sent by you). '
            .'Write the message yourself: warm, short, in the business’s voice; SMS at most 300 characters.';
    }

    public function parameters(): array
    {
        return [
            'name' => 'a short internal name',
            'channel' => 'email or sms',
            'segment' => 'who gets it: '.implode(', ', array_map(fn ($k, $v) => "{$k} ({$v})", array_keys($this->segments()), $this->segments())),
            'subject' => 'email subject (email only)',
            'body' => 'the message',
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
        $estimate = $this->sender->estimate($c['channel'], $c['segment'], null);
        $changes = ['New draft: '.$c['name'].' ('.($c['channel'] === 'sms' ? 'SMS' : 'email').')', 'To: '.$this->segments()[$c['segment']].' — about '.$estimate['recipients'].' people today'];
        if ($c['subject'] !== null) {
            $changes[] = 'Subject: '.$c['subject'];
        }
        $changes[] = 'Message: '.$c['body'];
        $changes[] = 'Nothing is sent: you’ll test and send it from Campaigns.';

        return ['summary' => "Save the draft campaign “{$c['name']}”", 'changes' => $changes];
    }

    public function apply(array $params): array
    {
        $c = $this->check($params);
        $id = (string) Str::ulid();
        DB::connection($this->context->connectionName())->table('campaigns')->insert(['id' => $id, 'tenant_id' => $this->context->id(), 'name' => $c['name'], 'channel' => $c['channel'],
            'segment' => $c['segment'], 'branch_id' => null, 'subject' => $c['subject'], 'body' => $c['body'], 'status' => 'draft',
            'created_by' => (string) auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('campaigns.drafted', 'campaign', $id, ['name' => $c['name'], 'via' => 'Ask WURK']);

        return ['done' => 'Draft saved. Open it to send yourself a test, then send it.', 'link' => route('tenant.campaigns.show', ['campaign' => $id]), 'undo' => ['id' => $id]];
    }

    public function undo(array $undo): bool
    {
        $deleted = DB::connection($this->context->connectionName())->table('campaigns')->where('tenant_id', $this->context->id())
            ->where('id', (string) ($undo['id'] ?? ''))->where('status', 'draft')->delete();
        if ($deleted === 0) {
            return false; // sent, scheduled or already gone
        }
        $this->audit->record('campaigns.draft_deleted', 'campaign', (string) $undo['id'], ['via' => 'Ask WURK (undo)']);

        return true;
    }

    /** @return array<string, string> */
    private function segments(): array
    {
        $all = [...CampaignsController::SEGMENTS, ...$this->audiences->available()];
        unset($all['branch']); // needs a branch chosen on the Campaigns screen

        return $all;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{name: string, channel: string, segment: string, subject: string|null, body: string}
     */
    private function check(array $p): array
    {
        $channel = (string) ($p['channel'] ?? '');
        if (! in_array($channel, ['email', 'sms'], true)) {
            throw new AssistantRefused('Email or SMS?');
        }
        $segment = (string) ($p['segment'] ?? 'all');
        if (! array_key_exists($segment, $this->segments())) {
            throw new AssistantRefused('Who should get it? '.implode(', ', $this->segments()).'.');
        }
        $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 120);
        $body = trim((string) ($p['body'] ?? ''));
        $subject = $channel === 'email' ? mb_substr(trim((string) ($p['subject'] ?? '')), 0, 150) : null;
        if ($body === '' || ($channel === 'email' && $subject === '')) {
            throw new AssistantRefused('The campaign needs a message'.($channel === 'email' ? ' and a subject' : '').'.');
        }
        if (mb_strlen($body) > ($channel === 'sms' ? 300 : 10000)) {
            throw new AssistantRefused($channel === 'sms' ? 'SMS messages are kept to 300 characters.' : 'That message is too long.');
        }

        return ['name' => $name === '' ? 'Campaign drafted by Ask WURK' : $name, 'channel' => $channel, 'segment' => $segment, 'subject' => $subject, 'body' => $body];
    }
}
