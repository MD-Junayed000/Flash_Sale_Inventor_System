<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class PurchaseCooldownException extends RuntimeException
{
    public function __construct(
        public readonly string $email,
        public readonly string $sku,
    ) {
        parent::__construct("User '{$email}' already purchased SKU '{$sku}' recently. Try again in a minute.");
    }
}
