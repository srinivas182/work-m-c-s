<?php

declare(strict_types=1);

namespace Wurk\Payments\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

/** Test and live keys kept separately; `credentials` is the current mode's (what the gateways use). */
trait HasPaymentKeys
{
    /** @return Attribute<array<string, string>, never> */
    protected function credentials(): Attribute
    {
        return Attribute::get(fn (): array => (array) (($this->mode === 'live' ? $this->live_credentials : $this->test_credentials) ?? []));
    }

    public function hasKeys(string $mode): bool
    {
        return (($mode === 'live' ? $this->live_credentials : $this->test_credentials) ?? []) !== [];
    }
}
