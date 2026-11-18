<?php

declare(strict_types=1);

namespace Wurk\Regions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A country's tax rate from a date (25.1). Older invoices keep the rate they were made with; a new rate starts on its
 * date. A rate is used only once HQ has confirmed it.
 *
 * @property int $id
 * @property string $country_code
 * @property int $rate_bp
 * @property list<array{name: string, bp: int}>|null $levies
 * @property CarbonImmutable $starts_on
 * @property string|null $note
 * @property CarbonImmutable|null $confirmed_at
 * @property string|null $confirmed_by
 * @property string|null $created_by
 */
final class TaxRate extends Model implements CentralModel
{
    protected $table = 'country_tax_rates';

    protected $fillable = ['country_code', 'rate_bp', 'levies', 'starts_on', 'note', 'confirmed_at', 'confirmed_by', 'created_by'];

    protected function casts(): array
    {
        return ['rate_bp' => 'integer', 'levies' => 'array', 'confirmed_at' => 'immutable_datetime'];
    }

    /** The day it starts, Y-m-d (stored as a date: MySQL gives it back as text, and so does SQLite). */
    public function startsOn(): string
    {
        return substr((string) $this->getAttributes()['starts_on'], 0, 10);
    }

    /** The rate plus its levies, in basis points. */
    public function totalBp(): int
    {
        return $this->rate_bp + array_sum(array_map(fn (array $l) => (int) $l['bp'], $this->levies ?? []));
    }
}
