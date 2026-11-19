<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

use Wurk\Platform\Money\Currency;

/** The currency of the business being served (its country pack, 25.1b); rand outside a business. */
interface BusinessCurrency
{
    public function current(): Currency;
}
