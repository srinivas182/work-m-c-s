<?php

declare(strict_types=1);

namespace Wurk\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $category_id
 * @property string $slug
 * @property string $title
 * @property string|null $summary
 * @property string $body
 * @property string|null $video_path
 * @property string|null $poster_path
 * @property list<string>|null $screens
 * @property string $status
 * @property int $position
 * @property int $views
 * @property int $helpful_yes
 * @property int $helpful_no
 * @property Carbon|null $published_at
 */
final class HelpArticle extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['screens' => 'array', 'published_at' => 'datetime', 'views' => 'integer', 'helpful_yes' => 'integer', 'helpful_no' => 'integer'];
    }
}
