<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * The current business's reviews as its website shows them (Sprint 25.3): every review counts in the average (hidden
 * ones too); the list has only the shown ones, newest first, never sorted by stars.
 */
interface PublicReviews
{
    /**
     * @return array{count: int, average: float|null, reviews: list<array{name: string, rating: int, comment: string|null, reply: string|null, date: string}>}
     */
    public function summary(int $limit = 12): array;
}
