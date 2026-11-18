<?php

declare(strict_types=1);

namespace Wurk\Ai\Drivers;

/** Normalises a provider's tool calls (Sprint 19.1): id, name, and arguments as a string-keyed array. */
final class ToolCalls
{
    /**
     * @param  iterable<array{0: mixed, 1: mixed, 2: mixed}>  $calls  [id, name, arguments] each
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    public static function of(iterable $calls): array
    {
        $out = [];
        foreach ($calls as [$id, $name, $arguments]) {
            $args = [];
            foreach (is_array($arguments) ? $arguments : [] as $k => $v) {
                $args[(string) $k] = $v;
            }
            $out[] = ['id' => is_scalar($id) ? (string) $id : '', 'name' => is_scalar($name) ? (string) $name : '', 'arguments' => $args];
        }

        return $out;
    }
}
