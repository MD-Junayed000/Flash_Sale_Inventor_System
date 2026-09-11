<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\OrderCreated;
use App\Exceptions\InactiveProductException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\PurchaseCooldownException;
use App\Http\Controllers\Controller;
use App\Services\Contracts\PurchaseServiceInterface;
use App\Services\DTOs\PurchaseResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Thin HTTP adapter for the purchase flow.
 *
 * This controller owns NOTHING but the wire protocol. All business decisions
 * (stock check, discount math, order persistence, event dispatch) happen in
 * {@see PurchaseServiceInterface}. The controller:
 *   1. Validates the request body.
 *   2. Calls the service with a resolved user identity.
 *   3. Maps domain exceptions to HTTP status codes.
 *   4. Returns the immutable {@see PurchaseResult} as a standard JSON envelope.
 *   5. Fires {@see OrderCreated} on the public broadcast channel (best-effort).
 */
final class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseServiceInterface $purchases,
    ) {
    }

    /**
     * POST /api/v1/purchase
     *
     * Required body fields:
     *   - sku            (string, must exist in products table)
     *   - quantity       (int, 1..10)
     *   - payment_ref    (string, 8..128 chars, opaque client-side identifier)
     *
     * Optional:
     *   - idempotency_key (string, max 128 chars; auto-generated if absent)
     *   - email           (only honoured when no auth context is attached)
     */
    public function store(Request $request): JsonResponse
    {
        $correlationId = (string) ($request->attributes->get('correlation_id') ?? '');
        $user          = $request->user();

        try {
            $validated = $this->validatePayload($request);
        } catch (ValidationException $e) {
            return $this->errorResponse(
                code:    'VALIDATION_FAILED',
                message: 'The given data was invalid.',
                status:  Response::HTTP_UNPROCESSABLE_ENTITY,
                errors:  $e->errors(),
            );
        }

        // Sanctum is the source of truth. Fall back to body.email only when
        // running behind a custom guard that does not bind a User model.
        $email = $user?->email ?? (string) ($validated['email'] ?? '');

        if ($email === '') {
            return $this->errorResponse(
                code:    'UNAUTHENTICATED',
                message: 'No authenticated identity is attached to this request.',
                status:  Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            $result = $this->purchases->attempt(
                email:          $email,
                sku:            $validated['sku'],
                quantity:       (int) $validated['quantity'],
                paymentRef:     isset($validated['payment_ref']) ? (string) $validated['payment_ref'] : null,
                idempotencyKey: (string) ($validated['idempotency_key'] ?? (string) Str::uuid()),
                userId:         $user?->getKey() !== null ? (int) $user->getKey() : null,
            );
        } catch (InsufficientStockException) {
            return $this->errorResponse(
                code:    'INSUFFICIENT_STOCK',
                message: 'There is not enough stock to satisfy the requested quantity.',
                status:  Response::HTTP_CONFLICT,
            );
        } catch (InactiveProductException) {
            return $this->errorResponse(
                code:    'PRODUCT_INACTIVE',
                message: 'The requested product is not available for purchase.',
                status:  Response::HTTP_CONFLICT,
            );
        } catch (ProductNotFoundException) {
            return $this->errorResponse(
                code:    'PRODUCT_NOT_FOUND',
                message: 'The requested product does not exist.',
                status:  Response::HTTP_NOT_FOUND,
            );
        } catch (PurchaseCooldownException $e) {
            return new JsonResponse([
                'success'        => false,
                'message'        => $e->getMessage(),
                'correlation_id' => $correlationId,
                'error'          => [
                    'code'    => 'PURCHASE_COOLDOWN',
                    'context' => ['retry_after' => $e->getRemainingSeconds()],
                ],
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $e->getRemainingSeconds()]);
        } catch (Throwable $e) {
            Log::error('PurchaseController.store failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return $this->errorResponse(
                code:    'INTERNAL_ERROR',
                message: 'We could not complete your purchase right now. Please try again.',
                status:  Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if ($result->success) {
            $this->broadcastOrderCreatedSafely($result);
            return new JsonResponse($result->toArray($correlationId), $result->httpStatus);
        }

        // Domain-level failure returned via PurchaseResult (validation/cooldown/snapshot
        // mismatch). Use the status and code the service decided on.
        return new JsonResponse(
            $result->toArray($correlationId),
            $result->httpStatus ?: Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    // ----------------------------------------------------------------- //
    //  Private helpers                                                   //
    // ----------------------------------------------------------------- //

    /**
     * Validate the inbound purchase request.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'sku'              => ['required', 'string', 'max:64'],
            'quantity'         => ['required', 'integer', 'min:1', 'max:10'],
            'payment_ref'      => ['nullable', 'string', 'min:8', 'max:128'],
            'idempotency_key'  => ['nullable', 'string', 'max:128'],
            'email'            => ['nullable', 'email', 'max:191'],
        ]);
    }

    /**
     * Build a uniform JSON error envelope (used by validation / 401 etc.).
     *
     * @param  array<string, mixed>  $errors
     */
    private function errorResponse(
        string $code,
        string $message,
        int    $status,
        array  $errors = [],
    ): JsonResponse {
        $context = $errors !== [] ? ['fields' => $errors] : [];

        $payload = PurchaseResult::fail(
            httpStatus: $status,
            message:    $message,
            errorCode:  $code,
            context:    $context,
        )->toArray((string) request()->attributes->get('correlation_id'));

        return new JsonResponse($payload, $status);
    }

    /**
     * Fire {@see OrderCreated} but never let a broadcast failure break the
     * purchase response. A failed broadcast is logged, not surfaced.
     */
    private function broadcastOrderCreatedSafely(PurchaseResult $result): void
    {
        try {
            // The DTO hydrates the Order from the service; pass the real model
            // so the broadcast payload can pull sku / quantity / timestamps.
            event(new OrderCreated($result->order));
        } catch (Throwable $e) {
            Log::error('OrderCreated broadcast failed', [
                'order_id' => $result->orderId,
                'reason'   => $e->getMessage(),
            ]);
        }
    }
}



