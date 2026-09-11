<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\DiscountServiceInterface;

/**
 * Mystery Discount service (Bonus task).
 *
 * Probability:
 *   - 20% chance: 10% discount
 *   -  5% chance: 50% discount
 *   - 75% chance: no discount
 *
 * Implementation: cumulative buckets using a 100-sided die.
 *   1..20  -> 10% discount  (20%)
 *   21..25 -> 50% discount  ( 5%)
 *   26..100-> 0%  discount  (75%)
 */
final class DiscountService implements DiscountServiceInterface
{
    public function roll(): int
    {
        $weights = config('purchase.discount_weights', [
            'none' => 75,
            'ten' => 20,
            'fifty' => 5,
        ]);
        $values = config('purchase.discount_values', ['none' => 0, 'ten' => 10, 'fifty' => 50]);
        $total = array_sum($weights);
        $roll = random_int(1, $total);
        $cursor = 0;

        foreach ($weights as $bucket => $weight) {
            $cursor += $weight;
            if ($roll <= $cursor) {
                return (int) $values[$bucket];
            }
        }

        return 0;
    }

    public function calculatePayable(float $unitPrice, int $quantity, int $discountPercentage): float
    {
        $subtotal = $unitPrice * $quantity;
        $discount = $subtotal * ($discountPercentage / 100);

        return round($subtotal - $discount, 2);
    }
}
