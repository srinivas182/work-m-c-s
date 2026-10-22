<?php

declare(strict_types=1);

namespace Wurk\Identity\Contracts;

/** Read and manage people for other modules (names, WURK staff). */
interface PeopleDirectory
{
    /** @return list<array{id: string, name: string, email: string|null, active: bool, mfa: bool, last_login: string|null}> */
    public function staff(): array;

    public function staffCount(): int;

    /**
     * How to reach people (for approval codes and notices).
     *
     * @param  list<string>  $ids
     * @return array<string, array{name: string, email: string|null, phone: string|null}>
     */
    public function contacts(array $ids): array;

    /** Whether the person has a confirmed authenticator app. */
    public function hasMfa(string $identityId): bool;

    /** Active WURK staff (deactivated staff keep their roles on record but lose all access). */
    public function isActiveStaff(string $identityId): bool;

    /**
     * @param  list<string>  $ids
     * @return array<string, string> id => name
     */
    public function names(array $ids): array;

    /** Deactivates WURK staff access and signs them out everywhere. */
    public function deactivateStaff(string $identityId): void;

    public function reactivateStaff(string $identityId): void;
}
