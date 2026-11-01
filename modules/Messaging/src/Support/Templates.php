<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Illuminate\Validation\ValidationException;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Messaging\Models\MessageTemplate;

/** The registry of customer messages (one per app) and each business's wording (its own database). */
final class Templates implements MessageTemplates
{
    public const CHANNELS = ['email', 'sms'];

    public const SMS_MAX = 306; // two SMS parts

    public const EMAIL_MAX = 2000;

    /** @var array<string, array{group: string, label: string, placeholders: list<string>, required: list<string>, subject: string, text: string, sample: array<string, string>, whatsapp?: bool, optional?: bool, channels?: list<string>}> */
    private array $definitions = [];

    public function define(string $key, array $definition): void
    {
        $this->definitions[$key] = $definition;
    }

    /** @return array<string, array{group: string, label: string, placeholders: list<string>, required: list<string>, subject: string, text: string, sample: array<string, string>, whatsapp?: bool, optional?: bool, channels?: list<string>}> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @param  array<string, string>  $vars */
    public function subject(string $key, array $vars): string
    {
        $def = $this->definition($key);
        $own = $this->own($key, 'email');

        return $this->fill($own?->subject ?: $def['subject'], $vars);
    }

    /** @param  array<string, string>  $vars */
    public function text(string $key, string $channel, array $vars): string
    {
        $def = $this->definition($key);

        $own = $this->own($key, $channel);

        return $this->fill($own === null ? $def['text'] : $own->text, $vars);
    }

    /**
     * What a channel says today (the business's wording, else the standard), unfilled.
     *
     * @return array{subject: string|null, text: string, own: bool}
     */
    public function current(string $key, string $channel): array
    {
        $def = $this->definition($key);
        $own = $this->own($key, $channel);

        return ['subject' => $channel === 'email' ? ($own?->subject ?: $def['subject']) : null, 'text' => $own === null ? $def['text'] : $own->text, 'own' => $own !== null];
    }

    /** Save a business's wording — only within the safety rails. */
    public function save(string $key, string $channel, ?string $subject, string $text, string $by): void
    {
        $def = $this->definition($key);
        $text = trim($text);
        $subject = $subject === null ? null : trim($subject);
        $problems = [];
        if (! in_array($channel, self::CHANNELS, true)) {
            $problems['channel'] = 'Only email and SMS can be reworded — WhatsApp uses Meta-approved wording.';
        } elseif (! in_array($channel, $def['channels'] ?? self::CHANNELS, true)) {
            $problems['channel'] = 'This message is only sent by '.implode(' and ', $def['channels'] ?? self::CHANNELS).'.';
        }
        foreach (array_filter([$subject, $text]) as $part) {
            preg_match_all('/\{([a-z_]+)\}/', $part, $m);
            $unknown = array_diff($m[1], $def['placeholders']);
            if ($unknown !== []) {
                $problems['text'] = 'Unknown placeholder: {'.implode('}, {', $unknown).'}. You can use {'.implode('}, {', $def['placeholders']).'}.';
            }
            if (preg_match('/<[a-z\/!][^>]*>/i', $part) === 1) {
                $problems['text'] = 'Plain text only — no HTML.';
            }
        }
        foreach ($def['required'] as $needed) {
            if (! str_contains($text, '{'.$needed.'}')) {
                $problems['text'] = 'Keep {'.$needed.'} — customers need it.';
            }
        }
        if ($text === '') {
            $problems['text'] = 'The message can’t be empty.';
        }
        $max = $channel === 'sms' ? self::SMS_MAX : self::EMAIL_MAX;
        if (mb_strlen($text) > $max) {
            $problems['text'] = $channel === 'sms' ? 'Too long for two SMS parts ('.self::SMS_MAX.' characters).' : 'Too long ('.self::EMAIL_MAX.' characters at most).';
        }
        if ($channel === 'email' && ($subject === null || $subject === '' || mb_strlen($subject) > 120)) {
            $problems['subject'] = 'Give the email a subject (up to 120 characters).';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
        MessageTemplate::query()->updateOrCreate(['key' => $key, 'channel' => $channel], ['subject' => $channel === 'email' ? $subject : null, 'text' => $text, 'updated_by' => $by]);
    }

    /** Back to the standard wording. */
    public function reset(string $key, string $channel): void
    {
        MessageTemplate::query()->where('key', $key)->where('channel', $channel)->delete();
    }

    /** @return array{group: string, label: string, placeholders: list<string>, required: list<string>, subject: string, text: string, sample: array<string, string>, whatsapp?: bool, optional?: bool, channels?: list<string>} */
    private function definition(string $key): array
    {
        return $this->definitions[$key] ?? throw new \InvalidArgumentException("Unknown message: {$key}");
    }

    private function own(string $key, string $channel): ?MessageTemplate
    {
        return MessageTemplate::query()->where('key', $key)->where('channel', $channel)->first();
    }

    /** @param array<string, string> $vars */
    private function fill(string $text, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{'.$k.'}'] = $v;
        }

        return strtr($text, $map);
    }
}
