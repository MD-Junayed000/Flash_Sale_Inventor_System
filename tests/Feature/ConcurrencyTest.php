<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Order;
use App\Models\Product;
use Tests\TestCase;

uses(RefreshDatabase::class);

it('never sells more units than the available stock', function (): void {
    config(['purchase.cooldown_seconds' => 0]);
    Product::create([
        'sku' => 'CONTENDED',
        'name' => 'Limited Stock',
        'price' => 1.00,
        'stock_quantity' => 5,
        'status' => 'active',
    ]);

    $success = 0;
    foreach (range(1, 20) as $i) {
        ['token' => $token] = $this->registerAndLogin("buyer{$i}@example.com");
        $response = $this->withToken($token)->postJson('/api/v1/purchase', [
            'sku' => 'CONTENDED',
            'quantity' => 1,
        ]);

        if ($response->status() === 201) {
            $success++;
        } else {
            $response->assertConflict();
        }
    }

    expect($success)->toBe(5);
    expect(Product::where('sku', 'CONTENDED')->value('stock_quantity'))->toBe(0);
    expect(Order::where('sku', 'CONTENDED')->sum('quantity'))->toBe(5);
});
