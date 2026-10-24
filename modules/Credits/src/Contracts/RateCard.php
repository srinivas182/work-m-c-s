<?php

declare(strict_types=1);

namespace Wurk\Credits\Contracts;

/** The current price in WURK Credits (thousandths) of one message or action, per country. */
interface RateCard
{
    public function rate(string $country, string $channel): ?int;
}
