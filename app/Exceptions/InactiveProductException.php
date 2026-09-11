<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InactiveProductException extends RuntimeException
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct("Product with SKU '{$sku}' is not available for purchase.");
    }
}
