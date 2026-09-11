<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrency invariant: with N concurrent purchase attempts of quantity=Q
 * on a stock of S, the system MUST satisfy  stock_after = max(0, S - N*Q).
 *
 * The test uses multiple users + multiple sequential calls rather than real
 * parallel threads so it can run in CI without pcntl / fork semantics.  The
 * critical thing we exercise is the transactional UPDATE inside
 * PurchaseService::attempt() – row locks via SELECT ... FOR UPDATE.
 *
 * We verify:
 *   * orders_placed + orders_rejected  =  attempts
 *   * stock_after  =  max(0, S - orders_placed * Q)
 *   * never sells more units than the available stock.
 */
uses(RefreshDatabase::class);

it('never oversells under contention', function () {
    Product::create([
        'sku'         => 'CONTENDED',
        'name'        => 'Limited Stock',
        'price'       => 100,
        'stock'       => 5,
        'status'      => 'active',
        'flash_sale'  => true,
        'starts_at'   => now()->subMinute(),
        'ends_at'     => now()->addHour(),
    ]);

    // 20 users, each trying to buy 1 of the 5-stock SKU.
    $users = collect(range(1, 20))->map(function ($i) {
        ['token' => $token] = $this->registerAndLogin("buyer{$i}@example.com");

        return $token;
    });

    $success = 0;
    $failed  = 0;

    foreach ($users as $token) {
        $response = $this->postJson('/api/v1/purchase',
            ['sku' => 'CONTENDED', 'quantity' => 1],
            ['Authorization' => "Bearer {$token}"]
        );

        if ($response->status() === 200) {
            $success++;
        } elseif ($response->status() === 409) {
            $failed++;
        } else {
            // Anything else is a bug.
            throw new \RuntimeException(
                'Unexpected status '.$response->status().': '.$response->getContent()
            );
        }
    }

    expect($success)->toBe(5);                 // Exactly 5 succeed.
    expect($failed)->toBe(15);                 // 15 are told "out_of_stock".
    expect(Product::where('sku', 'CONTENDED')->value('stock'))->toBe(0);
    expect(Order::where('sku', 'CONTENDED')->where('status', 'completed')->count())->toBe(5);
});

it('never sells more than available stock even when many users race', function () {
    Product::create([
        'sku'         => 'RACE-1',
        'name'        => 'Tight Stock',
        'price'       => 100,
        'stock'       => 3,
        'status'      => 'active',
        'flash_sale'  => true,
        'starts_at'   => now()->subMinute(),
        'ends_at'     => now()->addHour(),
    ]);

    // 50 users, quantity=1 – only 3 can win.
    $tokens = collect(range(1, 50))->map(function ($i) {
        ['token' => $t] = $this->registerAndLogin("race-{$i}@example.com");

        return $t;
    });

    $ok = 0;
    foreach ($tokens as $token) {
        $r = $this->postJson('/api/v1/purchase',
            ['sku' => 'RACE-1', 'quantity' => 1],
            ['Authorization' => "Bearer {$token}"]
        );
        if ($r->status() === 200) {
            $ok++;
        }
    }

    expect($ok)->toBe(3);
    expect(Order::where('sku', 'RACE-1')->sum('quantity'))->toBe(3);
});
