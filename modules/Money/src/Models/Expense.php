<?php

declare(strict_types=1);

namespace Wurk\Money\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * An expense (Sprint 23.5b).
 *
 * @property string $id
 * @property Carbon $spent_on
 * @property int $amount_cents
 * @property string $category
 * @property string|null $note
 * @property string|null $slip_path
 * @property bool $from_slip
 * @property string|null $created_by
 */
final class Expense extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'money_expenses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount_cents' => 'integer', 'from_slip' => 'boolean'];
    }
}
