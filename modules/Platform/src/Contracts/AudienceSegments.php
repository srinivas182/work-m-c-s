<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Extra campaign audiences other modules offer (Sprint 15.3b) — e.g. Referrals' "Your top referrers". Each returns the
 * CRM contact ids in it, or null when it doesn't apply to this business (Campaigns then leaves it out). CRM still keeps
 * only customers who agreed to marketing.
 */
interface AudienceSegments
{
    /** @param \Closure(): (list<string>|null) $contacts */
    public function register(string $key, string $label, \Closure $contacts): void;

    /** @return array<string, string> key => label, for the ones that apply here */
    public function available(): array;

    /** @return list<string>|null */
    public function contacts(string $key): ?array;
}
