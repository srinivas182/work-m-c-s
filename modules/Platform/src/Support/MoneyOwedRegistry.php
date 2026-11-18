<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Throwable;
use Wurk\Platform\Contracts\MoneyOwed;

final class MoneyOwedRegistry implements MoneyOwed
{
    /** @var array<string, \Closure> */
    private array $sources = [];

    public function register(string $module, \Closure $source): void
    {
        $this->sources[$module] = $source;
    }

    public function all(): array
    {
        $items = [];
        foreach ($this->sources as $module => $source) {
            try {
                foreach ($source() as $i) {
                    $item = ['key' => (string) $i['key'], 'kind' => (string) $i['kind'], 'title' => (string) $i['title'], 'customer' => (string) $i['customer'],
                        'phone' => isset($i['phone']) ? (string) $i['phone'] : null, 'email' => isset($i['email']) ? (string) $i['email'] : null,
                        'amount_cents' => (int) $i['amount_cents'], 'since' => (string) $i['since'], 'due' => isset($i['due']) ? (string) $i['due'] : null,
                        'link' => (string) $i['link'], 'module' => $module];
                    if (isset($i['settle'])) {
                        $item['settle'] = (string) $i['settle'];
                    }
                    $items[] = $item;
                }
            } catch (Throwable $e) {
                report($e); // one module failing never hides the rest
            }
        }
        usort($items, fn (array $a, array $b) => [$a['due'] ?? $a['since'], $a['since']] <=> [$b['due'] ?? $b['since'], $b['since']]);

        return $items;
    }
}
