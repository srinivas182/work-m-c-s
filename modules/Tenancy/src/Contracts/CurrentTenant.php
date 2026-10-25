<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/**
 * The business a request or job is running for. Other modules depend on this
 * interface, never on the Tenancy module's models.
 */
interface CurrentTenant
{
    public function tenantId(): string;

    public function handle(): string;

    public function displayName(): string;

    public function shardId(): string;

    public function isActive(): bool;

    /** ISO country code (e.g. "ZA"): currency, VAT and messaging rates follow it. */
    public function countryCode(): string;

    /** The business's time zone (e.g. Africa/Johannesburg): bookings are calculated in it. */
    public function timezone(): string;

    /** When the business was created (end of signup). */
    public function createdAt(): ?\DateTimeInterface;
}
