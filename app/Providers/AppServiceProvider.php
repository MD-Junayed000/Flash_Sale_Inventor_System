<?php

namespace App\Providers;

use App\Services\Contracts\DiscountServiceInterface;
use App\Services\Contracts\PurchaseServiceInterface;
use App\Services\DiscountService;
use App\Services\PurchaseService;
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
        //
    }
}
