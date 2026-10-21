<?php

declare(strict_types=1);

namespace Wurk\Audit\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Records create / update / delete of a model automatically, with before and
 * after values of changed attributes (hidden attributes and secrets redacted).
 * Action names: "{prefix}.created|updated|deleted"; the prefix defaults to the
 * snake-case model name (override auditPrefix()).
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => self::writeAudit($m, 'created', ['after' => $m->getAttributes()]));
        static::updated(function (Model $m): void {
            $changed = array_diff_key($m->getChanges(), array_flip(['updated_at']));
            if ($changed !== []) {
                self::writeAudit($m, 'updated', ['before' => array_intersect_key($m->getOriginal(), $changed), 'after' => $changed]);
            }
        });
        static::deleted(fn (Model $m) => self::writeAudit($m, 'deleted', ['before' => $m->getOriginal()]));
    }

    public function auditPrefix(): string
    {
        return str(class_basename($this))->snake()->toString();
    }

    /** @param  array<string, mixed>  $changes */
    private static function writeAudit(Model $model, string $event, array $changes): void
    {
        $hidden = array_flip($model->getHidden());
        foreach ($changes as $key => $values) {
            if (is_array($values)) {
                foreach (array_keys(array_intersect_key($values, $hidden)) as $field) {
                    $changes[$key][$field] = '[redacted]';
                }
            }
        }

        $prefix = method_exists($model, 'auditPrefix') ? $model->auditPrefix() : class_basename($model);
        app(AuditLog::class)->record($prefix.'.'.$event, class_basename($model), (string) $model->getKey(), $changes);
    }
}
