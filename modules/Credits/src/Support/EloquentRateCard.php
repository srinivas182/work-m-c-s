<?php

declare(strict_types=1);

namespace Wurk\Credits\Support;

use Wurk\Credits\Contracts\RateCard;
use Wurk\Credits\Models\CreditRate;

final class EloquentRateCard implements RateCard
{
    public function rate(string $country, string $channel): ?int
    {
        $rate = CreditRate::query()->where('country', $country)->where('channel', $channel)->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')->orderByDesc('id')->value('credits_milli');

        return $rate === null ? null : (int) $rate;
    }
}
