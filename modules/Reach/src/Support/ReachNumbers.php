<?php

declare(strict_types=1);

namespace Wurk\Reach\Support;

use Illuminate\Database\QueryException;
use Wurk\Reach\Models\ReachNumber;

/** Each business's short number inside WURK's USSD code (25.2): four digits, given once, never reused by another. */
final class ReachNumbers
{
    public function for(string $tenantId): ReachNumber
    {
        $existing = ReachNumber::query()->find($tenantId);
        if ($existing !== null) {
            return $existing;
        }
        for ($try = 0; $try < 20; $try++) {
            $number = (string) random_int(1000, 9999);
            if (ReachNumber::query()->where('number', $number)->exists()) {
                continue;
            }
            try {
                return ReachNumber::query()->create(['tenant_id' => $tenantId, 'number' => $number]);
            } catch (QueryException) {
                // taken a moment ago by another business (or this one): look again
                $existing = ReachNumber::query()->find($tenantId);
                if ($existing !== null) {
                    return $existing;
                }
            }
        }

        // Four digits nearly used up: five.
        return ReachNumber::query()->create(['tenant_id' => $tenantId, 'number' => (string) random_int(10000, 99999)]);
    }
}
