<?php

namespace App\Providers;

use App\Services\Contracts\DiscountServiceInterface;
use App\Services\Contracts\PurchaseServiceInterface;
use App\Services\DiscountService;
use App\Services\PurchaseService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Thin controllers depend on interfaces. Bind concrete implementations here.
        $this->app->bind(PurchaseServiceInterface::class, PurchaseService::class);
        $this->app->bind(DiscountServiceInterface::class, DiscountService::class);
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Named rate limiters consumed by route middleware (throttle:purchase, etc.).
     *
     *   * 'purchase'      – per-IP, 30 req/min.  Primary abuse-vector protection.
     *   * 'purchase.sku'  – per-IP-per-SKU, 10 req/min.  Prevents one IP draining
     *                       a single product.
     *   * 'auth'          – per-IP, 10 req/min.  Slows down credential stuffing.
     *   * 'read'          – per-IP, 120 req/min.  Catalogue / order reads.
     */
    protected function registerRateLimiters(): void
    {
        // 30/min per IP – primary throttle for /purchase.
        RateLimiter::for('purchase', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'error'   => 'rate_limited',
                        'message' => 'Too many purchase attempts. Slow down.',
                    ], 429);
                });
        });

        // Stricter per-IP+SKU throttle: 10/min.  Bound by the SKU carried in
        // the request body so a single IP can't drain a single product.
        RateLimiter::for('purchase.sku', function (Request $request) {
            $sku = (string) $request->input('sku', 'unknown');

            return Limit::perMinute(10)->by($request->ip().'|'.$sku)
                ->response(function () {
                    return response()->json([
                        'error'   => 'rate_limited',
                        'message' => 'Per-SKU purchase limit exceeded.',
                    ], 429);
                });
        });

        // Auth endpoints: 10/min per IP.  Throttles credential stuffing.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'error'   => 'rate_limited',
                        'message' => 'Too many auth attempts. Try again later.',
                    ], 429);
                });
        });

        // Read endpoints: 120/min per IP.  Generous because catalog browsing
        // is cheap and we don't want a SPA refresh loop to trigger 429s.
        RateLimiter::for('read', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
