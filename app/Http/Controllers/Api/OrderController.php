<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Customer-facing order history (the missing /api/v1/orders endpoints).
 *
 * Ownership is enforced by matching the order's `user_email` column with the
 * authenticated user's email — the schema intentionally stores email rather
 * than a foreign key to user_id so that orders survive user deletion and can
 * be queried without joining the users table on the hot read path.
 */
final class OrderController extends Controller
{
    /** List the authenticated user's last 50 orders. */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_email', $request->user()->email)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data'    => $orders,
            'message' => 'OK',
        ]);
    }

    /** Fetch a single order, enforcing that it belongs to the caller. */
    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless(
            $order->user_email === $request->user()->email,
            403,
            'Order does not belong to the authenticated user.',
        );

        return response()->json([
            'data'    => $order,
            'message' => 'OK',
        ]);
    }
}