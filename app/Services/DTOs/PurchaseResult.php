<?php

declare(strict_types=1);

namespace App\Services\DTOs;

/**
 * Standardized result of a purchase attempt.
 * The HTTP layer maps this to a JSON response (controllers stay thin).
 */
final readonly class PurchaseResult
{
    private function __construct(
        public bool $success,
        public ?int $orderId,
        public ?string $invoice,
        public int $discount,
        public float $payable,
        public int $statusCode,
        public ?string $message,
    ) {}

    public static function success(int $orderId, string $invoice, int $discount, float $payable): self
    {
        return new self(true, $orderId, $invoice, $discount, $payable, 200, null);
    }

    public static function failure(int $statusCode, string $message): self
    {
        return new self(false, null, null, 0, 0.0, $statusCode, $message);
    }

    public function toArray(): array
    {
        if ($this->success) {
            return [
                'success' => true,
                'order_id' => $this->orderId,
                'invoice' => $this->invoice,
                'discount' => $this->discount,
                'payable' => (float) $this->payable,
            ];
        }

        return [
            'success' => false,
            'message' => $this->message,
        ];
    }
}
