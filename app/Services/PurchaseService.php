<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Exceptions\InactiveProductException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PurchaseCooldownException;
use App\Jobs\ProcessOrder;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Services\Contracts\DiscountServiceInterface;
use App\Services\Contracts\PurchaseServiceInterface;
use App\Services\DTOs\PurchaseResult;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Core orchestration for purchase flow.
 *
 * Responsibilities:
 *   1. Cooldown check (Task 6)
 *   2. Find product + validate active (Task 2)
 *   3. Atomic stock decrement inside transaction (Task 5 - prevents overselling)
 *   4. Apply mystery discount (Bonus)
 *   5. Create order with status=pending
 *   6. Dispatch queue job (Task 3)
 *   7. Log activity (Task 7)
 *
 * Failures roll back the transaction (stock not decremented if order creation fails).
 */
final class PurchaseService implements PurchaseServiceInterface
{
    public function __construct(
        private readonly DiscountServiceInterface $discountService,
        private readonly CacheContract $cache,
    ) {}

    public function attempt(string $email, string $sku, int $quantity): PurchaseResult
    {
        // 1. Cooldown check (Task 6) - cheap, fail fast
        $cooldownKey = $this->cooldownKey($email, $sku);
        if ($this->cache->has($cooldownKey)) {
            $message = 'You already purchased this product recently.';
            $this->logFailure($email, $sku, $quantity, $message);

            return PurchaseResult::failure(429, $message);
        }

        try {
            // 2-6. Everything inside a single transaction
            $order = DB::transaction(function () use ($email, $sku, $quantity) {
                // Lock the product row to serialize concurrent buyers for this SKU.
                // The atomic WHERE-decrement below also guarantees correctness if the lock is bypassed.
                /** @var Product|null $product */
                $product = Product::query()
                    ->where('sku', $sku)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw new \App\Exceptions\ProductNotFoundException("Product with SKU '{$sku}' not found.");
                }

                if ($product->status !== ProductStatus::Active) {
                    throw new InactiveProductException($sku);
                }

                // Atomic conditional decrement. Even without the lock above, MySQL guarantees
                // correctness here because the WHERE clause is evaluated against committed data.
                $decremented = $product->decrementStock($quantity);
                if (! $decremented) {
                    throw new InsufficientStockException(
                        requested: $quantity,
                        available: (int) $product->fresh()?->stock_quantity ?? 0,
                    );
                }

                // Roll discount BEFORE creating the order (Bonus)
                $discount = $this->discountService->roll();
                $payable  = $this->discountService->calculatePayable(
                    (float) $product->price,
                    $quantity,
                    $discount,
                );

                return Order::create([
                    'product_id'          => $product->id,
                    'user_email'          => $email,
                    'sku'                 => $sku,
                    'quantity'            => $quantity,
                    'unit_price'          => $product->price,
                    'discount_percentage' => $discount,
                    'payable_amount'      => $payable,
                    'status'              => OrderStatus::Pending,
                ]);
            });
        } catch (InactiveProductException $e) {
            $this->logFailure($email, $sku, $quantity, 'Product is not active.');
            return PurchaseResult::failure(400, 'Product is not available for purchase.');
        } catch (InsufficientStockException $e) {
            $this->logFailure($email, $sku, $quantity, 'Insufficient stock.');
            return PurchaseResult::failure(400, 'Insufficient stock.');
        } catch (\App\Exceptions\ProductNotFoundException $e) {
            $this->logFailure($email, $sku, $quantity, 'Product not found.');
            return PurchaseResult::failure(404, 'Product not found.');
        } catch (Throwable $e) {
            // Unknown error - log full context, surface a generic message
            Log::error('Purchase failed unexpectedly', [
                'email' => $email,
                'sku' => $sku,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);
            $this->logFailure($email, $sku, $quantity, 'Internal error: ' . $e->getMessage());
            return PurchaseResult::failure(500, 'Could not process purchase.');
        }

        // 7. Set cooldown AFTER successful purchase (Task 6)
        $ttl = (int) config('purchase.cooldown_seconds', 60);
        $store = config('purchase.cooldown_store');
        if ($ttl > 0) {
            if ($store !== null && $store !== '') {
                \Illuminate\Support\Facades\Cache::store($store)->put($cooldownKey, true, $ttl);
            } else {
                $this->cache->put($cooldownKey, true, $ttl);
            }
        }

        // 8. Dispatch queue job (Task 3) - job will set status=completed + invoice
        ProcessOrder::dispatch($order->id);

        // 9. Log success (Task 7)
        $this->logSuccess($email, $sku, $quantity);

        return PurchaseResult::success(
            orderId: $order->id,
            invoice: $order->invoice_number ?? 'PENDING',
            discount: (int) $order->discount_percentage,
            payable: (float) $order->payable_amount,
        );
    }

    public function completeOrder(Order $order): void
    {
        $invoice = sprintf(
            'INV-%s-%05d',
            now()->format('Ymd'),
            $order->id,
        );

        $order->update([
            'invoice_number' => $invoice,
            'status' => OrderStatus::Completed,
            'failure_reason' => null,
        ]);

        Log::info('Order completed', [
            'order_id' => $order->id,
            'invoice' => $invoice,
        ]);
    }

    public function failOrder(Order $order, Throwable $e): void
    {
        $order->update([
            'status' => OrderStatus::Failed,
            'failure_reason' => $e->getMessage(),
        ]);

        Log::error('Order failed', [
            'order_id' => $order->id,
            'reason' => $e->getMessage(),
        ]);
    }

    private function cooldownKey(string $email, string $sku): string
    {
        return sprintf('purchase_cooldown:%s:%s', strtolower($email), strtoupper($sku));
    }

    private function logSuccess(string $email, string $sku, int $quantity): void
    {
        ActivityLog::create([
            'email' => $email,
            'sku' => $sku,
            'quantity' => $quantity,
            'status' => ActivityStatus::Success,
            'failure_reason' => null,
        ]);
    }

    private function logFailure(string $email, string $sku, int $quantity, string $reason): void
    {
        ActivityLog::create([
            'email' => $email,
            'sku' => $sku,
            'quantity' => $quantity,
            'status' => ActivityStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
