<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PurchaseCooldownException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        public readonly string $email,
        public readonly string $sku,
        public readonly int $remainingSeconds = 60,
    ) {
        parent::__construct("User '" . $email . "' already purchased SKU '" . $sku . "' recently. Try again in " . $remainingSeconds . "s.");
    }

    public function getRemainingSeconds(): int
    {
        return max(0, $this->remainingSeconds);
    }

    public function getStatusCode(): int
    {
        return 429;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [
            'Retry-After'     => (string) $this->getRemainingSeconds(),
            'X-Cooldown-Left' => (string) $this->getRemainingSeconds(),
        ];
    }
}