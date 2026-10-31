<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Wurk\Website\Models\ChatAnswer;
use Wurk\Website\Models\Site;

/**
 * The rule-based chatbot (no AI): ready-made answers from what WURK knows about the business (SiteFacts), which
 * follow the facts until the owner rewrites them, plus the owner's own buttons and keyword answers.
 */
final readonly class ChatBot
{
    /** Ready-made answers, in order: [fact, button label, keywords]. */
    private const READY = [
        ['hours', 'Opening hours', 'open, opening, hours, time, times, close, closing, today'],
        ['services', 'Prices', 'price, prices, cost, costs, how much, rate, rates, fee, fees, services'],
        ['address', 'Where are you?', 'where, address, location, directions, find you, map, parking'],
        ['phone', 'Call us', 'call, phone, number, contact, speak, talk'],
        ['whatsapp', 'WhatsApp us', 'whatsapp, message, text, chat'],
    ];

    public function __construct(private SiteFacts $facts) {}

    /** @return array{enabled: bool, greeting: string, fallback: string, hidden: list<string>, leads: bool} */
    public function settings(): array
    {
        $c = (array) (Site::query()->first()?->settings['chat'] ?? []);

        return [
            'enabled' => (bool) ($c['enabled'] ?? false), // off until the owner switches it on
            'greeting' => (string) ($c['greeting'] ?? 'Hi! How can we help? Tap a question or type your own.'),
            'fallback' => (string) ($c['fallback'] ?? 'I’m not sure about that one — would you like someone to get back to you?'),
            'hidden' => array_values(array_map('strval', (array) ($c['hidden'] ?? []))),
            'leads' => (bool) ($c['leads'] ?? true),
        ];
    }

    /** Create the ready-made answers the first time (only for facts the business has). */
    public function ensureReady(): void
    {
        if (ChatAnswer::query()->exists()) {
            return;
        }
        $facts = $this->facts->collect();
        $position = 0;
        foreach (self::READY as [$fact, $label, $keywords]) {
            $text = $this->factAnswer($fact, $facts);
            if ($text === null) {
                continue;
            }
            ChatAnswer::query()->create(['kind' => 'button', 'label' => $label, 'keywords' => $keywords, 'answer' => $text, 'fact' => $fact,
                ...$this->factLink($fact, $facts), 'position' => $position++]);
        }
    }

    /**
     * What the website's chat loads: the greeting, buttons, keyword answers and reply time. Ready-made answers the
     * owner hasn't rewritten are re-written from today's facts; an answer whose fact is gone is left out.
     *
     * @return array{greeting: string, fallback: string, hidden: list<string>, buttons: list<array{label: string, answer: string, link: array{kind: string, href: string}|null}>, keywords: list<array{words: list<string>, answer: string, link: array{kind: string, href: string}|null}>, reply: string}
     */
    public function publicConfig(): array
    {
        $s = $this->settings();
        $facts = $this->facts->collect();
        $buttons = $keywords = [];
        foreach (ChatAnswer::query()->orderBy('position')->get() as $a) {
            $answer = $a->fact !== null && ! $a->edited ? $this->factAnswer($a->fact, $facts) : $a->answer;
            if ($answer === null) {
                continue;
            }
            $link = $this->href($a->fact !== null && ! $a->edited ? $this->factLink($a->fact, $facts) : ['link_kind' => $a->link_kind, 'link_target' => $a->link_target], $facts);
            if ($a->kind === 'button' && $a->label !== null && $a->label !== '') {
                $buttons[] = ['label' => $a->label, 'answer' => $answer, 'link' => $link];
            }
            $words = array_values(array_filter(array_map(fn ($w) => mb_strtolower(trim($w)), explode(',', (string) $a->keywords))));
            if ($words !== []) {
                $keywords[] = ['words' => $words, 'answer' => $answer, 'link' => $link];
            }
        }

        return ['greeting' => $s['greeting'], 'fallback' => $s['fallback'], 'hidden' => $s['hidden'], 'buttons' => $buttons, 'keywords' => $keywords, 'reply' => $this->replyTime($facts)];
    }

    /**
     * Decision C: confirmed hours if the site has them; otherwise "as soon as we can".
     *
     * @param  array<string, mixed>|null  $facts
     */
    public function replyTime(?array $facts = null): string
    {
        $hours = ($facts ?? $this->facts->collect())['hours'];

        return $hours === null || $hours === []
            ? 'We’ll get back to you as soon as we can.'
            : 'We’ll get back to you during our opening hours ('.implode(', ', array_map(fn ($h) => $h['opens'].'–'.$h['closes'], $hours)).').';
    }

    /** @param array<string, mixed> $facts */
    private function factAnswer(string $fact, array $facts): ?string
    {
        $c = (array) $facts['contact'];

        return match ($fact) {
            'hours' => empty($facts['hours']) ? null : 'We’re open '.implode(', ', array_map(fn ($h) => $h['opens'].'–'.$h['closes'], (array) $facts['hours'])).'.',
            'services' => empty($facts['services']) ? null : implode("\n", array_map(fn ($sv) => $sv['name'].': '.($sv['from'] ? 'from ' : '').'R'.number_format($sv['price'] / 100, $sv['price'] % 100 ? 2 : 0), array_slice((array) $facts['services'], 0, 8))),
            'address' => ($c['address'] ?? '') === '' ? null : 'You’ll find us at '.$c['address'].'.',
            'phone' => ($c['phone'] ?? '') === '' ? null : 'Call us on '.$c['phone'].'.',
            'whatsapp' => ($c['whatsapp'] ?? '') === '' ? null : 'Send us a WhatsApp on '.$c['whatsapp'].'.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{link_kind: string, link_target: string|null}
     */
    private function factLink(string $fact, array $facts): array
    {
        return ['link_kind' => match ($fact) {
            'address' => 'map', 'phone' => 'call', 'whatsapp' => 'whatsapp', default => 'none'
        }, 'link_target' => null];
    }

    /**
     * @param  array{link_kind: string, link_target: string|null}  $link
     * @param  array<string, mixed>  $facts
     * @return array{kind: string, href: string}|null
     */
    private function href(array $link, array $facts): ?array
    {
        $c = (array) $facts['contact'];
        $digits = fn (string $n) => preg_replace('/\D+/', '', str_starts_with(trim($n), '0') ? '27'.substr(trim($n), 1) : $n);
        $href = match ($link['link_kind']) {
            'call' => ($c['phone'] ?? '') === '' ? null : 'tel:'.preg_replace('/[^\d+]/', '', (string) $c['phone']),
            'whatsapp' => ($c['whatsapp'] ?? '') === '' ? null : 'https://wa.me/'.$digits((string) $c['whatsapp']),
            'map' => ($c['address'] ?? '') === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string) $c['address']),
            'email' => ($c['email'] ?? '') === '' ? null : 'mailto:'.$c['email'],
            'page' => in_array($link['link_target'], array_column((array) $facts['pages'], 'path'), true) ? (string) $link['link_target'] : null, // only the site's own pages
            default => null,
        };

        return $href === null ? null : ['kind' => $link['link_kind'], 'href' => $href];
    }
}
