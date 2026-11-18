<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

/**
 * Voice notes customers send on WhatsApp (Sprint 21.4): how long they are, without decoding them. WhatsApp records
 * voice notes as Ogg Opus, whose last page carries the position in 48 kHz samples; other formats are judged by size.
 */
final class VoiceNotes
{
    /** Longer voice notes aren't transcribed (the customer is asked to type or send a shorter one). */
    public const MAX_SECONDS = 120;

    /** For formats whose length can't be read: about two minutes of a phone's audio. */
    public const MAX_UNKNOWN_BYTES = 2_500_000;

    /** How many days the audio is kept; the transcript stays with the chat (a server setting: WURK_VOICE_NOTE_DAYS). */
    public static function keepDays(): int
    {
        return max(1, (int) config('wurk.whatsapp.voice_note_days', 30));
    }

    /** The length in seconds, or null when it can't be read. */
    public static function seconds(string $audio, string $mime): ?float
    {
        if (! str_starts_with($audio, 'OggS') || ($head = strpos($audio, 'OpusHead')) === false) {
            return null;
        }
        $last = strrpos($audio, 'OggS');
        if ($last === false || strlen($audio) < $last + 14 || strlen($audio) < $head + 12) {
            return null;
        }
        $granule = unpack('P', substr($audio, $last + 6, 8));
        $preSkip = unpack('v', substr($audio, $head + 10, 2));
        if ($granule === false || $preSkip === false || $granule[1] < 0) {
            return null;
        }

        return max(0.0, ((int) $granule[1] - (int) $preSkip[1]) / 48000);
    }

    /** Whether it's too long to transcribe. */
    public static function tooLong(string $audio, ?float $seconds): bool
    {
        return $seconds !== null ? $seconds > self::MAX_SECONDS : strlen($audio) > self::MAX_UNKNOWN_BYTES;
    }
}
