<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end black-box test of /api/v1/purchase and friends.
 *
 * Covers the documented happy path, validation errors, missing SKU and the
 * activity-log side-effect.  Concurrency and discount-distribution tests
 * live in their own files because they need special fixtures.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Product::create([
        'sku'         => 'IPHONE-FLASH',
        'name'        => 'iPhone 15 Flash Sale',
        'price'       => 99900,    // 999.00 in cents
        'stock'       => 50,
        'status'      => 'active',
        'flash_sale'  => true,
        'starts_at'   => now()->subMinute(),
        'ends_at'     => now()->addHour(),
    ]);
});

it('returns 401 when unauthenticated', function () {
    $this->postJson('/api/v1/purchase', ['sku' => 'IPHONE-FLASH', 'quantity' => 1])
        ->assertStatus(401)
        ->assertJson(['error' => 'unauthenticated']);
});

it('validates input shape (quantity 1-10)', function () {
    ['token' => $token] = $this->registerAndLogin();

    $this->postJson('/api/v1/purchase',
        ['sku' => 'IPHONE-FLASH', 'quantity' => 0],
        ['Authorization' => "Bearer {$token}"]
    )->assertStatus(422)->assertJsonValidationErrors(['quantity']);

    $this->postJson('/api/v1/purchase',
        ['sku' => 'IPHONE-FLASH', 'quantity' => 11],
        ['Authorization' => "Bearer {$token}"]
    )->assertStatus(422)->assertJsonValidationErrors(['quantity']);
});

it('returns 404 for unknown SKU', function () {
    ['token' => $token] = $this->registerAndLogin();

    $this->postJson('/api/v1/purchase',
        ['sku' => 'NO-SUCH-SKU', 'quantity' => 1],
        ['Authorization' => "Bearer {$token}"]
    )->assertStatus(404)->assertJson(['error' => 'product_not_found']);
});

it('succeeds on a healthy stock product and writes activity log', function () {
    ['token' => $token, 'user' => $user] = $this->registerAndLogin();

    $before = Product::where('sku', 'IPHONE-FLASH')->value('stock');

    $this->postJson('/api/v1/purchase',
        ['sku' => 'IPHONE-FLASH', 'quantity' => 2],
        ['Authorization' => "Bearer {$token}"]
    )->assertOk()->assertJson([
        'success' => true,
        'sku'     => 'IPHONE-FLASH',
    ])->assertJsonPath('unit_price_cents', 99900)
      ->assertJsonStructure(['order_id', 'invoice_number', 'payable_cents']);

    // Stock decremented exactly by 2
    $this->assertSame($before - 2, Product::where('sku', 'IPHONE-FLASH')->value('stock'));

    // Activity log row created
    $this->assertDatabaseHas('activity_logs', [
        'user_email' => $user['email'],
        'sku'        => 'IPHONE-FLASH',
        'action'     => 'purchase',
        'status'     => 'success',
    ]);

    // Order persisted as Completed (sync queue ran)
    $this->assertDatabaseHas('orders', [
        'sku'      => 'IPHONE-FLASH',
        'quantity' => 2,
        'status'   => 'completed',
    ]);
});

it('returns 409 out-of-stock when stock is zero', function () {
    Product::where('sku', 'IPHONE-FLASH')->update(['stock' => 0]);
    ['token' => $token] = $this->registerAndLogin();

    $this->postJson('/api/v1/purchase',
        ['sku' => 'IPHONE-FLASH', 'quantity' => 1],
        ['Authorization' => "Bearer {$token}"]
    )->assertStatus(409)->assertJson(['error' => 'out_of_stock']);
});
