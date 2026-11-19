<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Throwable;
use Wurk\Platform\Contracts\FinishedVisits;

final class FinishedVisitsRegistry implements FinishedVisits
{
    /** @var array<string, \Closure> */
    private array $sources = [];

    public function register(string $module, \Closure $source): void
    {
        $this->sources[$module] = $source;
    }

    public function between(\DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $items = [];
        foreach ($this->sources as $module => $source) {
            try {
                foreach ($source($from, $until) as $i) {
                    $items[] = ['key' => (string) $i['key'], 'kind' => (string) $i['kind'], 'title' => (string) $i['title'], 'customer' => (string) $i['customer'],
                        'phone' => isset($i['phone']) && $i['phone'] !== '' ? (string) $i['phone'] : null, 'email' => isset($i['email']) && $i['email'] !== '' ? (string) $i['email'] : null,
                        'finished_at' => (string) $i['finished_at'], 'module' => $module];
                }
            } catch (Throwable $e) {
                report($e); // one module failing never hides the rest
            }
        }
        usort($items, fn (array $a, array $b) => $a['finished_at'] <=> $b['finished_at']);

        return $items;
    }
}
