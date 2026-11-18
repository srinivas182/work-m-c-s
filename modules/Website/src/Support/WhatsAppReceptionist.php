<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;

/**
 * The AI receptionist on the business's WhatsApp number (Sprint 21.3): the same knowledge, tools, guardrails and
 * hand-over as on the website, answering with WhatsApp's own controls — reply buttons (up to 3) and lists (up to 10)
 * for free times, services, classes and products, and a link button for paying or managing a booking.
 *
 * It never leaves a customer in silence: when it's off, over its monthly limit or out of credits, the conversation goes
 * to the team in Chats and the customer is told someone will reply.
 */
final readonly class WhatsAppReceptionist
{
    /** WhatsApp's limits for interactive messages. */
    public const BUTTONS = 3;

    public const ROWS = 10;

    public const PASSED_ON = 'Thanks for your message — someone from the team will reply as soon as they can.';

    public function __construct(private Receptionist $receptionist, private WhatsAppBusiness $whatsapp, private SiteFacts $facts, private TenantContext $context) {}

    /** Whether new WhatsApp conversations start with the assistant (switched on for WhatsApp, and a number connected). */
    public function on(): bool
    {
        return $this->receptionist->settings()['whatsapp'] && $this->whatsapp->connected();
    }

    /** Answers the conversation's latest customer message, unless a person has it or it's already answered. */
    public function answer(ReceptionistConversation $c): void
    {
        $last = ReceptionistMessage::query()->where('conversation_id', $c->id)->orderByDesc('position')->first();
        if ($c->status !== 'ai' || $last === null || $last->role !== 'user') {
            return;
        }
        $to = (string) $c->phone;
        if (! $this->on()) {
            $this->passOn($c, $to); // switched off since this chat began: the team has it now

            return;
        }
        $closed = $this->closedNow();
        if ($closed && ! $this->awaySentToday($c)) {
            $away = $this->receptionist->settings()['away'];
            $this->record($c, $away, $this->whatsapp->sendText($to, $away), ['away' => true]);
        }

        $r = $this->receptionist->answer($c, $closed);
        if ($r['mode'] === 'rules') { // over the monthly limit, out of credits, or WURK AI unavailable
            $this->passOn($c, $to);

            return;
        }
        if ($r['mode'] !== 'ai' || $r['reply'] === null) {
            return;
        }
        $sent = $this->send($to, $r['reply'], $r['choices']);
        $message = $r['message'] !== null ? ReceptionistMessage::query()->find($r['message']) : null;
        $message?->update(['external_id' => $sent->ok ? $sent->providerId : null, 'delivery' => $sent->ok ? 'sent' : 'failed',
            'delivery_error' => $sent->ok ? null : mb_substr((string) $sent->error, 0, 300)]);
        if (! $sent->ok) { // e.g. out of credits for WhatsApp messages: the team takes it from here
            $c->update(['status' => 'waiting', 'keep' => true]);

            return;
        }
        foreach ($r['cards'] as $card) {
            $this->sendCard($c, $to, $card);
        }
    }

    /**
     * The reply, with the choices as reply buttons (up to 3) or a list (up to 10).
     *
     * @param  list<array{title: string, description: string}>  $choices
     */
    public function send(string $to, string $text, array $choices): SendResult
    {
        if ($choices === []) {
            return $this->whatsapp->sendText($to, $text);
        }
        $body = $text;
        if (mb_strlen($text) > 1024) { // too long for an interactive message's body: the text first, then the choices
            $first = $this->whatsapp->sendText($to, $text);
            if (! $first->ok) {
                return $first;
            }
            $body = 'Tap one to choose:';
        }
        $choices = array_slice($choices, 0, self::ROWS);
        $unique = count(array_unique(array_map(fn (array $c) => mb_substr($c['title'], 0, 20), $choices))) === count($choices);
        $interactive = count($choices) <= self::BUTTONS && $unique
            ? ['type' => 'button', 'body' => ['text' => $body], 'action' => ['buttons' => array_map(fn (array $c, int $i) => [
                'type' => 'reply', 'reply' => ['id' => 'choice:'.$i, 'title' => mb_substr($c['title'], 0, 20)]], $choices, array_keys($choices))]]
            : ['type' => 'list', 'body' => ['text' => $body], 'action' => ['button' => 'Choose', 'sections' => [['title' => 'Options', 'rows' => array_map(
                fn (array $c, int $i) => ['id' => 'choice:'.$i, 'title' => mb_substr($c['title'], 0, 24)] + ($c['description'] !== '' ? ['description' => mb_substr($c['description'], 0, 72)] : []),
                $choices, array_keys($choices))]]]];

        return $this->whatsapp->sendInteractive($to, $interactive);
    }

    /**
     * A tool's card (a booking made, a cart ready) with its link as a WhatsApp link button: pay the deposit, change or
     * cancel, check out.
     *
     * @param  array<string, mixed>  $card
     */
    private function sendCard(ReceptionistConversation $c, string $to, array $card): void
    {
        $url = (string) ($card['action']['url'] ?? '');
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            return;
        }
        $lines = array_filter(array_map(fn ($k) => is_scalar($card[$k] ?? null) ? trim((string) $card[$k]) : '', ['service', 'when', 'price', 'deposit']));
        $text = mb_substr(implode("\n", $lines) ?: 'Here’s your link.', 0, 1024);
        $label = mb_substr((string) ($card['action']['label'] ?? 'Open'), 0, 20);
        $sent = $this->whatsapp->sendInteractive($to, ['type' => 'cta_url', 'body' => ['text' => $text],
            'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $label, 'url' => $url]]]);
        $this->record($c, $text."\n".$label.': '.$url, $sent, ['card' => true]);
    }

    /** The team has the chat; the customer hears so once (not again until they've had a reply). */
    private function passOn(ReceptionistConversation $c, string $to): void
    {
        $c->update(['status' => 'waiting', 'keep' => true]);
        $lastOut = ReceptionistMessage::query()->where('conversation_id', $c->id)->whereIn('role', ['assistant', 'person'])->orderByDesc('position')->first();
        if (($lastOut?->content['passed_on'] ?? false) !== true) {
            $this->record($c, self::PASSED_ON, $this->whatsapp->sendText($to, self::PASSED_ON), ['passed_on' => true]);
        }
    }

    /**
     * Whether the business is closed now, from the opening hours on its website (once the owner confirmed them).
     * Unknown hours count as open.
     */
    public function closedNow(?CarbonImmutable $at = null): bool
    {
        try {
            $hours = $this->facts->collect()['hours'];
        } catch (Throwable) {
            return false;
        }
        if (! is_array($hours) || count($hours) !== 7) {
            return false;
        }
        $now = ($at ?? CarbonImmutable::now())->setTimezone($this->context->current()->timezone());
        $today = $hours[$now->isoWeekday() - 1];
        $opens = trim($today['opens']);
        $closes = trim($today['closes']);
        if ($opens === '') {
            return true; // closed all day
        }
        if (preg_match('/^\d{1,2}[:h]\d{2}$/', $opens) !== 1 || preg_match('/^\d{1,2}[:h]\d{2}$/', $closes) !== 1) {
            return false;
        }
        $minutes = fn (string $t) => (int) preg_replace('/[:h]\d{2}$/', '', $t) * 60 + (int) substr($t, -2);
        $now = $now->hour * 60 + $now->minute;
        $from = $minutes($opens);
        $to = $minutes($closes) <= $from ? 24 * 60 : $minutes($closes);

        return $now < $from || $now >= $to;
    }

    private function awaySentToday(ReceptionistConversation $c): bool
    {
        $midnight = CarbonImmutable::now($this->context->current()->timezone())->startOfDay()->utc();

        return ReceptionistMessage::query()->where('conversation_id', $c->id)->where('content->away', true)->where('created_at', '>=', $midnight)->exists();
    }

    /**
     * Keeps a message the assistant sent on WhatsApp in the conversation (and in the AI's history), with its ticks.
     *
     * @param  array<string, mixed>  $flags
     */
    private function record(ReceptionistConversation $c, string $text, SendResult $sent, array $flags): void
    {
        DB::connection($this->context->connectionName())->transaction(function () use ($c, $text, $sent, $flags) {
            $position = (int) ReceptionistMessage::query()->where('conversation_id', $c->id)->lockForUpdate()->max('position') + 1;
            ReceptionistMessage::query()->create(['conversation_id' => $c->id, 'position' => $position, 'role' => 'assistant',
                'content' => ['role' => 'assistant', 'content' => $text] + $flags, 'external_id' => $sent->ok ? $sent->providerId : null,
                'delivery' => $sent->ok ? 'sent' : 'failed', 'delivery_error' => $sent->ok ? null : mb_substr((string) $sent->error, 0, 300), 'created_at' => now()]);
        });
        $c->update(['last_message_at' => now()]);
    }
}
