<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

/** Development and tests only: a soft abstract gradient — clearly not a photo of anything real. */
final class SimulatedImageDriver implements ImageDriver
{
    public static int $failNext = 0;

    public function generate(string $model, string $prompt, string $apiKey): string
    {
        if (app()->environment('production')) {
            throw new ProviderFailed('The simulated provider is never used in production.');
        }
        if (self::$failNext > 0) {
            self::$failNext--;
            throw new ProviderFailed('Simulated failure');
        }
        $w = 1200;
        $h = 800;
        $img = imagecreatetruecolor($w, $h);
        $seed = crc32($prompt);
        [$r1, $g1, $b1] = [($seed >> 16) & 255, ($seed >> 8) & 255, $seed & 255];
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            imagefilledrectangle($img, 0, $y, $w, $y, (int) imagecolorallocate($img, self::channel($r1, 245, $t), self::channel($g1, 240, $t), self::channel($b1, 235, $t)));
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** @return int<0, 255> */
    private static function channel(int $from, int $to, float $t): int
    {
        return max(0, min(255, (int) ($from * (1 - $t) + $to * $t)));
    }
}
