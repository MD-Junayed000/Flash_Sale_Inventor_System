<?php

declare(strict_types=1);

namespace App\Services\Contracts;

interface DiscountServiceInterface
{
    /**
     * Roll a mystery discount for an order.
     * Probability distribution:
     *   20% chance -> 10% discount
     *    5% chance -> 50% discount
     *   75% chance -> 0% discount
     */
    public function roll(): int;

    /**
     * Calculate final payable amount given unit price, quantity, and discount percentage.
     */
    public function calculatePayable(float $unitPrice, int $quantity, int $discountPercentage): float;
}
