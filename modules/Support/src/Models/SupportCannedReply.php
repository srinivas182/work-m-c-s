<?php

declare(strict_types=1);

namespace Wurk\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A ready-written reply (Sprint 16.3b).
 *
 * @property string $id
 * @property string $title
 * @property string $body
 * @property int $uses
 * @property string|null $updated_by
 */
final class SupportCannedReply extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['uses' => 'integer'];
    }
}
