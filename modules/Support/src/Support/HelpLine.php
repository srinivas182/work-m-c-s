<?php

declare(strict_types=1);

namespace Wurk\Support\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;
use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Contracts\AskWurk;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Messaging\Contracts\WurkWhatsApp;
use Wurk\Platform\I18n\Locales;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * The WhatsApp help line (Sprint 25.6, decision H).
 *
 * - Owners and their team message WURK's own WhatsApp number from the mobile number confirmed on their WURK ID.
 *   Ask WURK answers from the help centre, in the language they chose in WURK (Sprint 24), with a link to the article.
 *   Voice notes are transcribed first (Sprint 21).
 * - It never makes changes and never reads the business's figures: those stay in the app, confirmed there.
 * - Each question counts toward Ask WURK's free questions for the month; after that it quotes the credits and asks
 *   for YES before spending them.
 * - "PERSON" hands over to WURK support: a ticket with the conversation attached; support's replies come back on
 *   WhatsApp (and by email).
 * - Someone in more than one business is answered for the first business they joined.
 */
final readonly class HelpLine
{
    /** Words that ask for a person, in the languages WURK speaks. */
    public const PERSON = ['person', 'human', 'agent', 'support', 'talk to a person', 'umuntu', 'mntu', 'persoon', 'motho', 'mtu', 'personne', 'pessoa', 'mutum', 'eniyan'];

    public const YES = ['yes', 'y', 'yebo', 'ewe', 'ja', 'ee', 'ndiyo', 'oui', 'sim', 'eh', 'bẹẹni'];

    /** A voice note longer than this (about three minutes) isn't transcribed. */
    public const VOICE_MAX_BYTES = 3_000_000;

    /** How long a "Reply YES to use credits" offer stays open. */
    public const QUOTE_MINUTES = 15;

    public function __construct(private Accounts $accounts, private TenantDirectory $tenants, private TenantContext $context, private AskWurk $ask,
        private Ai $ai, private WurkWhatsApp $whatsapp, private Tickets $tickets, private AuditLog $audit) {}

    /** Answers one message to the help line. `$from` is digits only. */
    public function handle(string $from, string $text, ?string $mediaId, ?string $mediaMime): void
    {
        $caller = $this->accounts->byVerifiedPhone($from);
        $tenant = $caller === null ? null : ($this->tenants->activeAmong($caller['tenants'])[0] ?? null);
        if ($caller === null || $tenant === null) {
            // Once a day at most, so a wrong number isn't answered over and over.
            if (Cache::add('helpline:stranger:'.$from, true, now()->addDay())) {
                $this->whatsapp->sendText($from, (string) __('This is WURK’s help line for businesses. Message us from the mobile number on your WURK account (confirmed in your profile), or visit :url.',
                    ['url' => 'https://'.config('wurk.domains.root').'/help']));
            }

            return;
        }
        $lang = Locales::supported((string) $caller['locale']) ? (string) $caller['locale'] : 'en';
        $say = fn (string $line, array $replace = []) => (string) __($line, $replace, $lang);

        $this->context->run($tenant, function () use ($caller, $from, $text, $mediaId, $mediaMime, $lang, $say): void {
            $question = trim($text);
            if ($question === '' && $mediaId !== null) {
                $question = $this->listen($mediaId, (string) $mediaMime);
                if ($question === null) {
                    $this->whatsapp->sendText($from, $say('Sorry, I couldn’t listen to that voice note. Please type your question, or send a shorter voice note.'));

                    return;
                }
            }
            if ($question === '') {
                return;
            }
            $word = mb_strtolower(trim($question, " \t\n\r\0\x0B.!?"));

            if (in_array($word, self::PERSON, true)) {
                $this->handOver($caller['id'], $from, $say);

                return;
            }
            $pending = Cache::pull('helpline:quote:'.$from);
            if (! is_string($pending) && mb_strlen(preg_replace('/[\d\s\p{P}]+/u', '', $question) ?? '') < 3) {
                // "1", "ok", "?" — not a question (a summary reply after it closed, say): never spend one on it.
                $this->whatsapp->sendText($from, $say('Ask your question in a few words, or send a voice note.'));

                return;
            }
            $accept = false;
            if (is_string($pending) && in_array($word, self::YES, true)) {
                [$question, $accept] = [$pending, true];
            }

            try {
                $result = $this->ask->helpLine($caller['id'], $question, $lang, $accept);
            } catch (Throwable) {
                $result = ['status' => 'failed'];
            }
            $more = $say('Reply PERSON to talk to someone at WURK.');
            $reply = match ($result['status']) {
                'answered' => trim((string) ($result['answer'] ?? ''))
                    .(($url = $result['sources'][0]['url'] ?? null) !== null ? "\n\n".$say('Read more: :url', ['url' => $url]) : '')
                    ."\n\n".$more,
                'quote' => $this->quote($from, $question, (float) ($result['credits'] ?? 0), $say),
                'refused' => trim((string) ($result['message'] ?? ''))."\n\n".$more,
                default => $say('Sorry, I can’t answer right now.').' '.$more,
            };
            $this->whatsapp->sendText($from, $reply);
            $this->audit->record('support.help_line', 'help_line', $this->context->id(), ['status' => $result['status'], 'voice' => $mediaId !== null, 'by' => $caller['id']]);
        });
    }

    /** @param callable(string, array<string, string>=): string $say */
    private function quote(string $from, string $question, float $credits, callable $say): string
    {
        Cache::put('helpline:quote:'.$from, $question, now()->addMinutes(self::QUOTE_MINUTES));

        return $say('You’ve used this month’s free questions. This one uses :credits credit. Reply YES to ask it.', ['credits' => rtrim(rtrim(number_format($credits, 3, '.', ''), '0'), '.')]);
    }

    /** @param callable(string, array<string, string>=): string $say */
    private function handOver(string $identityId, string $from, callable $say): void
    {
        $lines = array_map(fn (array $e) => "Q: {$e['question']}\nA: ".($e['answer'] ?? '—'), $this->ask->helpLineConversation($identityId));
        $body = "Asked for a person on the WhatsApp help line (+{$from}).\n\n".($lines === [] ? '(No questions before this.)' : implode("\n\n", $lines));
        $ticket = $this->tickets->open($this->context->id(), $identityId, 'WhatsApp help line', $body, ['page' => Tickets::HELP_LINE], []);
        $this->whatsapp->sendText($from, $say('Thanks — someone from WURK support will reply here on WhatsApp, usually within a working day. Your reference is :ref.',
            ['ref' => $ticket->reference()]));
    }

    private function listen(string $mediaId, string $mime): ?string
    {
        $file = $this->whatsapp->media($mediaId);
        if ($file === null || strlen($file['contents']) > self::VOICE_MAX_BYTES) {
            return null;
        }
        try {
            $heard = $this->ai->transcribe($file['contents'], $file['mime'] !== '' ? $file['mime'] : $mime, null, $this->context->id());
        } catch (AiUnavailable) {
            return null;
        }

        return $heard->unclear() || trim($heard->text) === '' ? null : mb_substr(trim($heard->text), 0, 500);
    }
}
