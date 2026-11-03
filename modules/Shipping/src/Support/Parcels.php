<?php

declare(strict_types=1);

namespace Wurk\Shipping\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Shipping\Contracts\ParcelPlanner;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class Parcels implements ParcelPlanner
{
    /** Couriers' size-based weight: L×W×H in cm ÷ 5,000 = kg. */
    public const VOLUMETRIC_DIVISOR = 5000;

    public function __construct(private TenantContext $context) {}

    public function plan(array $items): array
    {
        $db = DB::connection($this->context->connectionName());
        $default = (int) ($db->table('shipping_preferences')->value('default_item_g') ?? 500);
        $grams = 0;
        $volume = 0;            // mm³ of the items with sizes
        $largest = [0, 0, 0];   // the largest item, its sides sorted
        $volumetricG = 0;       // the size-based weight of items with sizes
        foreach ($items as $i) {
            $q = max(0, $i['quantity']);
            $g = $i['weight_g'] ?? $default;
            $grams += $g * $q;
            if ($i['size_mm'] !== null) {
                $sides = $i['size_mm'];
                rsort($sides);
                $volume += $sides[0] * $sides[1] * $sides[2] * $q;
                $largest = $sides[0] * $sides[1] * $sides[2] > $largest[0] * $largest[1] * $largest[2] ? $sides : $largest;
                $volumetricG += (int) round(($sides[0] / 10) * ($sides[1] / 10) * ($sides[2] / 10) / self::VOLUMETRIC_DIVISOR * 1000) * $q;
            }
        }
        $weightKg = max(0.1, round($grams / 1000, 3));
        $boxes = $db->table('shipping_boxes')->get();
        if ($boxes->isEmpty()) {
            return ['weight_kg' => $weightKg, 'size_cm' => null, 'box' => null, 'fits' => true]; // weight only, as before boxes
        }
        $fitting = $boxes->filter(function ($b) use ($volume, $largest, $grams): bool {
            $sides = [(int) $b->length_mm, (int) $b->width_mm, (int) $b->height_mm];
            rsort($sides);

            return $volume <= $sides[0] * $sides[1] * $sides[2] && $sides[0] >= $largest[0] && $sides[1] >= $largest[1] && $sides[2] >= $largest[2]
                && $grams <= (int) $b->max_weight_g;
        })->sortBy(fn ($b) => (int) $b->length_mm * (int) $b->width_mm * (int) $b->height_mm);
        $box = $fitting->first();
        if ($box === null) {
            // No box fits: rate on whichever is more — the actual weight or the size-based weight — and warn.
            return ['weight_kg' => max($weightKg, round($volumetricG / 1000, 3)), 'size_cm' => null, 'box' => null, 'fits' => false];
        }

        return ['weight_kg' => $weightKg, 'size_cm' => ['length' => $box->length_mm / 10, 'width' => $box->width_mm / 10, 'height' => $box->height_mm / 10], 'box' => (string) $box->name, 'fits' => true];
    }
}
