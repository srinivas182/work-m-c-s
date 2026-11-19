<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/**
 * Where owners' own domains get their certificate and reach WURK (Sprint 17.3): Cloudflare for SaaS in the cloud.
 *
 * Each result is ['id', 'status' (pending|active|moved|deleted|…), 'ssl' (pending_validation|active|…), 'problems' (plain
 * words from the provider)]. Throws CustomHostnameFailed when the provider refuses or can't be reached.
 */
interface CustomHostnames
{
    public function configured(): bool;

    /** @return array{id: string, status: string, ssl: string, problems: list<string>} */
    public function create(string $host): array;

    /** @return array{id: string, status: string, ssl: string, problems: list<string>}|null null when it no longer exists */
    public function get(string $id): ?array;

    public function delete(string $id): void;
}
