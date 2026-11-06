<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/** The business's contacts: one per person, built from everything they do. */
interface ContactBook
{
    /**
     * Remember someone from a booking, order, class place, form reply or chat: matched by exact phone or email
     * (phones normalised), else a new contact. Returns the contact's id. Marketing consent is recorded only when given.
     */
    public function remember(string $name, ?string $phone, ?string $email, string $sourceType, string $sourceId, ?string $branchId, string $summary, ?string $consentSource = null): string;

    /**
     * Contacts' names, by id (for other modules' screens).
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function names(array $ids): array;

    /** The contact a source record (an order, booking, class place, sale…) belongs to, if any. */
    public function contactFor(string $sourceType, string $sourceId): ?string;

    /**
     * Find a customer by phone or email — normalised and matched exactly, like remember().
     *
     * @return array{id: string, name: string, phone: string|null, email: string|null}|null
     */
    public function find(?string $phone, ?string $email = null): ?array;

    /**
     * Contacts' emails, by id (only those that have one).
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function emails(array $ids): array;

    /**
     * A customer's history of the given kinds (e.g. order, booking, class, sale), newest first.
     *
     * @param  list<string>  $types
     * @return list<array{type: string, summary: string, at: \DateTimeInterface}>
     */
    public function history(string $contactId, array $types, int $limit = 50): array;

    /**
     * Link a record (e.g. an in-store sale) to a customer already known by id — as remember() does for a name and
     * phone; raises ContactRemembered the same way.
     */
    public function link(string $contactId, string $sourceType, string $sourceId, ?string $branchId, string $summary): void;
}
