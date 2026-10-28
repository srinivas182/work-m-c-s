<?php

declare(strict_types=1);

return [
    // How long patient records are kept, from the patient's most recent document (agreed with WURK, 30 Oct 2026).
    'retention_years' => 6,
    // A patient's private link, and its one-time code.
    'link_days' => 14,
    'code_minutes' => 10,
    'code_attempts' => 5,
    'session_minutes' => 30,
];
