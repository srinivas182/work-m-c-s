<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a model tenant data:
 *  - it lives on the current tenant's shard connection;
 *  - every query is filtered to the current tenant;
 *  - tenant_id is filled automatically on create;
 *  - using the model with no tenant context, or touching another tenant's row, throws.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $query->where($query->qualifyColumn('tenant_id'), static::tenantContext()->id());
        });

        static::creating(function (Model $model): void {
            $current = static::tenantContext()->id();
            $assigned = $model->getAttribute('tenant_id');

            if ($assigned === null) {
                $model->setAttribute('tenant_id', $current);
            } elseif ($assigned !== $current) {
                throw TenantContextMissing::mismatch($model::class, $current, (string) $assigned);
            }
        });

        foreach (['updating', 'deleting'] as $event) {
            static::$event(function (Model $model): void {
                $current = static::tenantContext()->id();
                $owner = (string) $model->getOriginal('tenant_id');

                if ($owner !== $current || $model->getAttribute('tenant_id') !== $current) {
                    throw TenantContextMissing::mismatch($model::class, $current, $owner);
                }
            });
        }
    }

    public function getConnectionName(): string
    {
        $context = static::tenantContext();

        if (! $context->has()) {
            throw TenantContextMissing::forModel(static::class);
        }

        return $context->connectionName();
    }

    protected static function tenantContext(): TenantContext
    {
        return app(TenantContext::class);
    }
}
