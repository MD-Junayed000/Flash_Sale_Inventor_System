<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseRequest;
use App\Services\Contracts\PurchaseServiceInterface;
use App\Services\DTOs\PurchaseResult;
use Illuminate\Http\JsonResponse;

/**
 * Thin controller: validates input, delegates to the service, returns JSON.
 * All business logic lives in PurchaseService so it can be reused from
 * console commands (concurrency test) and tested in isolation.
 */
final class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseServiceInterface $purchaseService,
    ) {}

    public function store(PurchaseRequest $request): JsonResponse
    {
        $result = $this->purchaseService->attempt(
            email: $request->email(),
            sku: (string) $request->validated('sku'),
            quantity: (int) $request->validated('quantity'),
        );

        return response()->json($result->toArray(), $result->statusCode);
    }
}
