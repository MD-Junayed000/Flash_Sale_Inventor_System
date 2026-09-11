<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /api/v1/purchase endpoint MUST be idempotent when called with the
 * same Idempotency-Key header.  We assert three guarantees:
 *
 *   1. The second call returns the SAME order id and invoice number.
 *   2. Stock is decremented ONCE, not twice.
 *   3. The replayed response carries the X-Idempotent-Replayed header.
 *
 * Also: the middleware MUST NOT cache non-2xx responses, otherwise a transient
 * 409 would be replayed for 24 h.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Product::create([
        'sku'         => 'FLASH-IDEMP',
        'name'        => 'Flash Sale Item',
        'price'       => 19900,
        'stock'       => 10,
        'status'      => 'active',
        'flash_sale'  => true,
        'starts_at'   => now()->subMinute(),
        'ends_at'     => now()->addHour(),
    ]);
});

it('replays the original response on duplicate Idempotency-Key', function () {
    ['token' => $token] = $this->registerAndLogin();
    $key = 'idem-'.bin2hex(random_bytes(8));

    $headers = [
        'Authorization'   => "Bearer {$token}",
        'Idempotency-Key' => $key,
    ];

    $first = $this->postJson('/api/v1/purchase',
        ['sku' => 'FLASH-IDEMP', 'quantity' => 1],
        $headers
    )->assertOk();

    $firstOrderId    = $first->json('order_id');
    $firstInvoice    = $first->json('invoice_number');
    $stockAfterFirst = Product::where('sku', 'FLASH-IDEMP')->value('stock');

    $second = $this->postJson('/api/v1/purchase',
        ['sku' => 'FLASH-IDEMP', 'quantity' => 1],
        $headers
    )->assertOk();

    expect($second->json('order_id'))->toBe($firstOrderId);
    expect($second->json('invoice_number'))->toBe($firstInvoice);
    expect($second->headers->get('X-Idempotent-Replayed'))->toBe('1');

    // Stock not decremented twice
    expect(Product::where('sku', 'FLASH-IDEMP')->value('stock'))->toBe($stockAfterFirst);

    // Only one Order row
    expect(Order::where('sku', 'FLASH-IDEMP')->count())->toBe(1);
});

it('does not replay when a different Idempotency-Key is used', function () {
    ['token' => $token] = $this->registerAndLogin();

    $first = $this->postJson('/api/v1/purchase',
        ['sku' => 'FLASH-IDEMP', 'quantity' => 1],
        ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'key-A']
    )->assertOk();

    $second = $this->postJson('/api/v1/purchase',
        ['sku' => 'FLASH-IDEMP', 'quantity' => 1],
        ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'key-B']
    )->assertOk();

    expect($second->json('order_id'))->not->toBe($first->json('order_id'));
});

it('treats missing Idempotency-Key as always-different', function () {
    ['token' => $token] = $this->registerAndLogin();

    // No header on either call – should still both succeed.
    $this->postJson('/api/v1/purchase',
        ['sku' => 'FLASH-IDEMP', 'quantity' => 1],
        ['Authorization' => "Bearer {$token}"]
    )->assertOk();

    $this->postJson('/api/v1/purchase',
        ['sku' => 'FLASH-IDEMP', 'quantity' => 1],
        ['Authorization' => "Bearer {$token}"]
    )->assertOk();

    expect(Order::where('sku', 'FLASH-IDEMP')->count())->toBe(2);
});
