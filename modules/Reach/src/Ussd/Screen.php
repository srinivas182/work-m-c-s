<?php

declare(strict_types=1);

namespace Wurk\Reach\Ussd;

/**
 * One USSD screen (25.2): a few lines of text and up to seven numbered choices, never more than 182 characters —
 * what every network shows on any phone. Long lists are paged ("8 More", "0 Back"); long names are shortened.
 */
final readonly class Screen
{
    public const MAX = 182;

    public const PER_PAGE = 7;

    /**
     * @param  list<string>  $choices  shown as "1 …", "2 …"; a key's position + 1 is what the caller types
     */
    public function __construct(public string $text, public array $choices = [], public bool $end = false, public bool $more = false, public bool $back = false) {}

    public static function end(string $text): self
    {
        return new self($text, end: true);
    }

    public static function ask(string $text): self
    {
        return new self($text);
    }

    public function render(): string
    {
        $tail = [];
        if ($this->more) {
            $tail[] = '8 More';
        }
        if ($this->back) {
            $tail[] = '0 Back';
        }
        $lines = array_map(fn (string $c, int $i) => ($i + 1).' '.$c, $this->choices, array_keys($this->choices));
        $out = trim($this->text."\n".implode("\n", $lines)."\n".implode('  ', $tail));
        if (mb_strlen($out) <= self::MAX) {
            return $out;
        }
        // Too long: shorten the choices evenly, then the text, so every choice stays visible.
        $room = self::MAX - mb_strlen(trim($this->text."\n".implode('  ', $tail))) - count($lines);
        $each = max(6, intdiv(max(0, $room), max(1, count($lines))));
        $lines = array_map(fn (string $l) => mb_strlen($l) > $each ? rtrim(mb_substr($l, 0, $each - 1)).'…' : $l, $lines);
        $out = trim($this->text."\n".implode("\n", $lines)."\n".implode('  ', $tail));

        return mb_strlen($out) <= self::MAX ? $out : rtrim(mb_substr($out, 0, self::MAX - 1)).'…';
    }
}
