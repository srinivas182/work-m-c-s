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
}
