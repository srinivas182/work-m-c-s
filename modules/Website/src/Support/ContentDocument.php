<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Validation\ValidationException;

/**
 * WURK's page content format — ours, not the editor's (Sprint 4 decision 1):
 *   { "schema": 1, "blocks": [ { "id": "…", "type": "Hero", "version": 1, "props": { … } } ] }
 * The editor adapter converts to and from it; every block carries its own
 * version so blocks can be upgraded later without breaking saved pages.
 */
final class ContentDocument
{
    /** Site paths (/contact), on-page anchors (#book), https://, mailto: and tel: only. */
    public const SAFE_LINK = '#^(/(?!/)[^\s]*|\#[A-Za-z][\w-]*|https://[^\s]+|mailto:[^\s]+|tel:\+?[0-9 ]{6,20})$#';

    public const SCHEMA = 1;

    /** @return array{schema: int, blocks: list<array{id: string, type: string, version: int, props: array<string, mixed>}>} */
    public static function empty(): array
    {
        return ['schema' => self::SCHEMA, 'blocks' => []];
    }

    /**
     * What a new page starts with, so nobody faces a blank canvas. Props are empty:
     * each block fills in its own defaults when rendered (and upgrades from version 1).
     *
     * @param  list<string|array{0: string, 1: array<string, mixed>}>  $blocks  a type, or [type, props to set]
     * @return array{schema: int, blocks: list<array{id: string, type: string, version: int, props: array<string, mixed>}>}
     */
    public static function starter(array $blocks): array
    {
        return ['schema' => self::SCHEMA, 'blocks' => array_map(function (string|array $block): array {
            [$type, $props] = is_array($block) ? $block : [$block, []];

            return ['id' => strtolower($type).'-'.bin2hex(random_bytes(5)), 'type' => $type, 'version' => 1, 'props' => $props];
        }, $blocks)];
    }

    /**
     * @return array{schema: int, blocks: list<array{id: string, type: string, version: int, props: array<string, mixed>}>}
     *
     * @throws ValidationException
     */
    public static function validate(mixed $document): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['content' => $message]);

        if (! is_array($document) || ($document['schema'] ?? null) !== self::SCHEMA || ! is_array($document['blocks'] ?? null) || ! array_is_list($document['blocks'])) {
            $fail('This page content is not in a format WURK understands.');
        }
        if (strlen((string) json_encode($document)) > (int) config('website.max_document_bytes')) {
            $fail('This page is too large. Split it into more pages.');
        }
        if (count($document['blocks']) > (int) config('website.max_blocks')) {
            $fail('This page has too many sections. Split it into more pages.');
        }

        $blocks = [];
        $ids = [];
        foreach ($document['blocks'] as $i => $block) {
            $n = $i + 1;
            if (! is_array($block)) {
                $fail("Section {$n} is not valid.");
            }
            $id = $block['id'] ?? null;
            $type = $block['type'] ?? null;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) !== 1 || isset($ids[$id])) {
                $fail("Section {$n} has a missing or duplicate id.");
            }
            if (! is_string($type) || preg_match('/^[A-Z][A-Za-z0-9]{1,40}$/', $type) !== 1) {
                $fail("Section {$n} has an unknown type.");
            }
            if (! is_int($block['version'] ?? null) || $block['version'] < 1) {
                $fail("Section {$n} has no version.");
            }
            $props = $block['props'] ?? [];
            if (! is_array($props) || ($props !== [] && array_is_list($props))) {
                $fail("Section {$n} has invalid settings.");
            }
            // Every link field (named …Href, at any depth) must be a safe link — never javascript: or data:.
            array_walk_recursive($props, function ($value, $key) use ($fail, $n): void {
                if (is_string($key) && str_ends_with($key, 'Href') && is_string($value) && $value !== '' && preg_match(self::SAFE_LINK, $value) !== 1) {
                    $fail("Section {$n} has a link we can’t use. Use a page (/contact), a section (#book), https://, mailto: or tel:.");
                }
            });
            $ids[$id] = true;
            $blocks[] = ['id' => $id, 'type' => $type, 'version' => $block['version'], 'props' => $props];
        }

        return ['schema' => self::SCHEMA, 'blocks' => $blocks];
    }
}
