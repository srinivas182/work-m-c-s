<?php

declare(strict_types=1);

namespace Wurk\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * Subtitles for a help video in one language (Sprint 25.6).
 *
 * @property string $id
 * @property string $article_id
 * @property string $locale
 * @property string $vtt
 * @property string $status draft | approved
 * @property bool $drafted_by_ai
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
final class HelpVideoCaption extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['drafted_by_ai' => 'boolean', 'reviewed_at' => 'datetime'];
    }
}
