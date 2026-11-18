<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * A module's part of setting a business up from what the owner said (Sprint 23.1): the website, services and hours, or
 * products. It works only from the brief, which holds the owner's own words, so nothing is invented. It runs inside the
 * business, after the owner has seen the preview and confirmed.
 *
 * The brief: name, what, area, address, phone, whatsapp, email, category,
 * services (list of {name, price_cents, minutes}), products (list of {name, price_cents}),
 * hours (seven rows, Monday first, {opens, closes}, empty = closed), bookings (bool), shop (bool).
 */
interface SetupStep
{
    /** Runs in this order (lower first): the website before what it shows. */
    public function order(): int;

    /**
     * What it will do, a few words a line ("Add 4 services with their prices"); empty when it has nothing to do.
     *
     * @param  array<string, mixed>  $brief
     * @return list<string>
     */
    public function preview(array $brief): array;

    /**
     * Do it. Returns what was done, a few words a line.
     *
     * @param  array<string, mixed>  $brief
     * @return list<string>
     */
    public function apply(array $brief, string $by): array;
}
