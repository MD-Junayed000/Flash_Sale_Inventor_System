<?php

declare(strict_types=1);

namespace App\Services\DTOs;

use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Immutable result returned by {@see \App\Services\PurchaseService::attempt()}.
 *
 * Money fields are expressed in BDT (৳) as **cents (integer)**. We choose
 * integer cents over floats to avoid floating-point drift both server-side
 * and on JS clients when they sum totals.
 *
 * The DTO is the contract between the service layer and the HTTP layer.
 */
final readonly class PurchaseResult
{
    public function __construct(
        public bool          $success           = false,
        public int           $httpStatus        = 200,
        public string        $message           = '',
        public ?int          $orderId           = null,
        public ?string       $orderUuid         = null,
        public ?OrderStatus  $status            = null,
        public ?int          $unitPrice         = null,   // cents
        public ?int          $payableAmount     = null,   // cents
        public ?int          $discountPercentage = null,
        public ?Order        $order             = null,
        public ?string       $errorCode         = null,
        public array         $context           = [],
    ) {
    }

    /**
     * Build a success result from a freshly-created Order.
     */
    public static function ok(Order $order): self
    {
        // Convert decimal money columns to cents (handle nullables defensively).
        $unitPriceCents = self::dollarsToCents((float) ($order->unit_price ?? 0));
        $payableCents   = self::dollarsToCents((float) ($order->payable_amount ?? 0));

        return new self(
            success:            true,
            httpStatus:         201,
            message:            'Purchase accepted; processing asynchronously.',
            orderId:            (int) $order->id,
            orderUuid:          (string) ($order->invoice_number ?? ''),
            status:             $order->status instanceof OrderStatus
                ? $order->status
                : OrderStatus::from((string) $order->status),
            unitPrice:          $unitPriceCents,
            payableAmount:      $payableCents,
            discountPercentage: (int) ($order->discount_percentage ?? 0),
            order:              $order,
        );
    }

    /**
     * Build a failure result. The controller maps this to a JSON error envelope.
     */
    public static function fail(
        int    $httpStatus,
        string $message,
        string $errorCode,
        array  $context = [],
    ): self {
        return new self(
            success:    false,
            httpStatus: $httpStatus,
            message:    $message,
            errorCode:  $errorCode,
            context:    $context,
        );
    }

    /**
     * Build the JSON envelope the API will serialize.
     *
     * @return array<string, mixed>
     */
    public function toArray(?string $correlationId = null): array
    {
        $payload = [
            'success'        => $this->success,
            'message'        => $this->message,
            'correlation_id' => $correlationId,
            'data'           => null,
            'error'          => null,
        ];

        if ($this->success) {
            $payload['data'] = [
                'order_id'            => $this->orderId,
                'invoice_number'      => $this->order?->invoice_number,
                'sku'                 => $this->order?->sku,
                'quantity'            => $this->order?->quantity,
                'status'              => $this->status?->value,
                'unit_price_cents'    => $this->unitPrice,
                'payable_amount_cents' => $this->payableAmount,
                'discount_percentage' => $this->discountPercentage,
            ];
        } else {
            $payload['error'] = [
                'code'    => $this->errorCode,
                'context' => $this->context ?: new \stdClass(),
            ];
        }

        return $payload;
    }

    /** Convert BDT dollars (decimal) to integer cents. */
    private static function dollarsToCents(float $dollars): int
    {
        return (int) round($dollars * 100);
    }
}
