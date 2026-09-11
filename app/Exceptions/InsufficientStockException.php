<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Insufficient stock. Requested: {$requested}, Available: {$available}.");
    }
}
