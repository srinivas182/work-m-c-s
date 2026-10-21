<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/**
 * Marker for models stored in the central database (not tenant data).
 * Every model must either implement this or use BelongsToTenant — an
 * architecture test enforces the choice.
 */
interface CentralModel {}
