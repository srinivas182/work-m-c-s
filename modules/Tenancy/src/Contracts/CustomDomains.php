<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/**
 * Owners' own domains (Sprint 17.3), for the current business. Settings → Your domain uses it; Admin → Domains sees
 * every business's.
 */
interface CustomDomains
{
    /** The request attribute holding the business's own domain the visitor used (null on its WURK address). */
    public const REQUEST_HOST = 'wurk.custom_host';

    /** @return list<array{id: string, host: string, status: string, ssl: string|null, primary: bool, problem: string|null, checked_at: string|null, added_at: string}> */
    public function list(): array;

    /** Adds a domain to the current business and asks for its certificate. Throws ValidationException with plain words. */
    public function add(string $host, string $by): string; // returns the normalised host

    /** Asks the domain service how it's going; returns the new status. */
    public function check(string $domainId): string;

    /** Makes an active domain the business's main address (its wurk.co.za address then redirects there). */
    public function makePrimary(string $domainId, string $by): string; // returns the host

    /** Stops using a domain; the wurk.co.za address becomes the main one again if it was. */
    public function remove(string $domainId, string $by): string; // returns the host
}
