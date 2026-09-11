<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ListProductsRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Public catalogue (the missing /api/v1/products endpoints).
 *
 * Returns a paginated slice of the active catalogue.  `stock_quantity` is
 * returned so that the client UI can render an "X left" badge without a
 * second round-trip, but the field is also protected by the
 * `purchase` rate limiter when the actual purchase attempt fires, so the
 * value is only advisory.
 */
final class ProductController extends Controller
{
    public function index(ListProductsRequest $request): JsonResponse
    {
        $perPage = (int) min(max($request->integer('per_page', 25), 1), 100);

        $products = Product::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data'    => $products,
            'message' => 'OK',
        ]);
    }

    public function show(string $sku): JsonResponse
    {
        $product = Product::query()
            ->where('sku', $sku)
            ->where('status', 'active')
            ->firstOrFail();

        return response()->json([
            'data'    => $product,
            'message' => 'OK',
        ]);
    }
}