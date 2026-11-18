<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $person
 * @property array<string, string> $answers question key => what the owner said
 * @property array<string, mixed> $brief what WURK understood (see SetupStep)
 * @property string $status
 */
final class SetupInterview extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['answers' => 'array', 'brief' => 'array'];
    }
}
