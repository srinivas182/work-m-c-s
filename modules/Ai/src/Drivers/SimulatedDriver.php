<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

/**
 * Stands in for a real provider until WURK's API keys arrive (and in tests).
 * Never used in production. Answers with valid JSON when asked for JSON.
 */
final class SimulatedDriver implements AiDriver
{
    /** Tests: make the next N calls fail (to exercise fallback and refunds). */
    public static int $failNext = 0;

    /** Tests: the exact text to answer with next, if set. */
    public static ?string $nextAnswer = null;

    public function name(): string
    {
        return 'simulated';
    }

    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $apiKey): array
    {
        if (app()->environment('production')) {
            throw new ProviderFailed('The simulated provider is never used in production.');
        }
        if (self::$failNext > 0) {
            self::$failNext--;
            throw new ProviderFailed('Simulated failure');
        }
        $text = self::$nextAnswer ?? $this->fillSlots($prompt) ?? (str_contains($system.$prompt, 'JSON') ? (string) json_encode(['note' => 'simulated']) : 'Simulated answer.');
        self::$nextAnswer = null;

        return ['text' => $text, 'input_tokens' => (int) ceil(strlen($system.$prompt) / 4), 'output_tokens' => (int) ceil(strlen($text) / 4)];
    }

    /** For "write my website": fill every listed slot with clearly simulated copy. */
    private function fillSlots(string $prompt): ?string
    {
        if (preg_match('/SLOTS[^:]*: (\{.*\})\s*$/s', $prompt, $m) !== 1) {
            return null;
        }
        preg_match('/^Business: (.*)$/m', $prompt, $b);
        $business = trim($b[1] ?? 'Your business');
        $pages = [];
        foreach ((array) json_decode($m[1], true) as $page => $blocks) {
            foreach ((array) $blocks as $id => $fields) {
                foreach ((array) $fields as $field => $max) {
                    $pages[$page][$id][$field] = mb_substr("[Simulated] {$business} — {$field}", 0, (int) $max);
                }
            }
        }
        $seo = array_map(fn () => ['title' => "[Simulated] {$business}", 'description' => "[Simulated] {$business} — sample description."], $pages);

        return (string) json_encode(['pages' => $pages, 'seo' => $seo]);
    }
}
