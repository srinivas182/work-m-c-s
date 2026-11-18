<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

/** What a voice note said (Sprint 21.4): the words, their language (ISO 639-1) where known, and how sure WURK AI is (0–1). */
final readonly class Transcript
{
    /** Below this, the receptionist reads the words back and asks the customer to confirm or type. */
    public const SURE = 0.6;

    public function __construct(public string $text, public ?string $language, public ?float $confidence) {}

    public function unclear(): bool
    {
        return trim($this->text) === '' || ($this->confidence !== null && $this->confidence < self::SURE);
    }
}
