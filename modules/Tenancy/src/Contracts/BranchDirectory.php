<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/** The current business's branches (every business has at least its main branch). */
interface BranchDirectory
{
    /**
     * Open branches, the main one first.
     *
     * @return list<array{id: string, name: string, code: string, address: string|null, phone: string|null, main: bool}>
     */
    public function open(): array;

    /** Whether the business has more than one open branch (so customers choose). */
    public function several(): bool;

    /** The main branch's id. */
    public function main(): string;

    /**
     * Every branch, closed ones included (for managing them).
     *
     * @return list<array{id: string, name: string, address: string|null, phone: string|null, main: bool, closed: bool}>
     */
    public function all(): array;

    /** Add a branch; returns its id. */
    public function add(string $name, ?string $address, ?string $phone): string;

    public function update(string $id, string $name, ?string $address, ?string $phone): void;

    /** Close or reopen a branch (its history is kept). The main branch can't be closed. */
    public function setClosed(string $id, bool $closed): void;
}
