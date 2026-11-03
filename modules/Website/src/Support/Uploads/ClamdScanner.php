<?php

declare(strict_types=1);

namespace Wurk\Website\Support\Uploads;

/** ClamAV's clamd over TCP, using its INSTREAM command (the file is streamed in chunks; nothing is written to clamd's disk). */
final class ClamdScanner implements VirusScanner
{
    public function scan(string $contents): ?string
    {
        $socket = @fsockopen((string) config('wurk.clamav.host'), (int) config('wurk.clamav.port'), $errno, $error, 5);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, (int) config('wurk.clamav.timeout', 30));
        fwrite($socket, "zINSTREAM\0");
        foreach (str_split($contents, 8192) as $chunk) {
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }
        fwrite($socket, pack('N', 0));
        $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        fclose($socket);

        return match (true) {
            str_ends_with($reply, 'OK') => self::CLEAN,
            str_ends_with($reply, 'FOUND') => self::INFECTED,
            default => null, // an error reply: hold the file
        };
    }
}
