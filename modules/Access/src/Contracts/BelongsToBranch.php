<?php

declare(strict_types=1);

namespace Wurk\Access\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Wurk\Access\AccessManager;

/**
 * Branch-scoped tenant data (bookings, stock, orders…). For team members
 * restricted to certain branches (e.g. branch managers), every query is
 * limited to their branches. Owners and unrestricted members see all branches.
 * Background jobs run without a signed-in person and are not restricted.
 *
 * Use together with BelongsToTenant; the model needs a branch_id column.
 *
 * @mixin Model
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query): void {
            $identityId = Auth::id();
            if ($identityId === null) {
                return;
            }

            $branches = app(AccessManager::class)->branchRestriction((string) $identityId);
            if ($branches !== null) {
                $query->whereIn($query->qualifyColumn('branch_id'), $branches);
            }
        });
    }
}
