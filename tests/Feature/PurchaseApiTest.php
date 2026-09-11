<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Product::create([
        'sku' => 'IPHONE-FLASH',
        'name' => 'iPhone 15 Flash Sale',
        'price' => 999.00,
        'stock_quantity' => 50,
        'status' => 'active',
    ]);
});

it('rejects unauthenticated purchases', function (): void {
    $this->postJson('/api/v1/purchase', ['sku' => 'IPHONE-FLASH', 'quantity' => 1])
        ->assertUnauthorized();
});

it('validates the purchase quantity', function (): void {
    ['token' => $token] = $this->registerAndLogin();

    $this->withToken($token)->postJson('/api/v1/purchase', [
        'sku' => 'IPHONE-FLASH',
        'quantity' => 0,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('returns not found for an unknown SKU and records the attempt', function (): void {
    ['token' => $token, 'user' => $user] = $this->registerAndLogin();

    $this->withToken($token)->postJson('/api/v1/purchase', [
        'sku' => 'NO-SUCH-SKU',
        'quantity' => 1,
    ])->assertNotFound()->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');

    $this->assertDatabaseHas('activity_logs', [
        'email' => $user['email'],
        'sku' => 'NO-SUCH-SKU',
        'status' => 'failed',
    ]);
});

it('reserves stock, creates an order, and completes it through the queue', function (): void {
    ['token' => $token, 'user' => $user] = $this->registerAndLogin();
    $before = Product::where('sku', 'IPHONE-FLASH')->value('stock_quantity');

    $response = $this->withToken($token)->postJson('/api/v1/purchase', [
        'sku' => 'IPHONE-FLASH',
        'quantity' => 2,
    ])->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.order_id', 1)
        ->assertJsonPath('data.sku', 'IPHONE-FLASH')
        ->assertJsonPath('data.quantity', 2);

    expect(Product::where('sku', 'IPHONE-FLASH')->value('stock_quantity'))->toBe($before - 2);
    expect($response->json('data.status'))->toBe('pending');

    $this->assertDatabaseHas('orders', [
        'sku' => 'IPHONE-FLASH',
        'quantity' => 2,
        'status' => 'completed',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'email' => $user['email'],
        'sku' => 'IPHONE-FLASH',
        'status' => 'success',
    ]);
});

it('rejects a second purchase for the same SKU during cooldown', function (): void {
    ['token' => $token] = $this->registerAndLogin();
    $body = ['sku' => 'IPHONE-FLASH', 'quantity' => 1];

    $this->withToken($token)->postJson('/api/v1/purchase', $body)->assertCreated();
    $this->withToken($token)->postJson('/api/v1/purchase', $body)
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'PURCHASE_COOLDOWN');
});

it('returns conflict when stock is insufficient', function (): void {
    Product::where('sku', 'IPHONE-FLASH')->update(['stock_quantity' => 0]);
    ['token' => $token] = $this->registerAndLogin();

    $this->withToken($token)->postJson('/api/v1/purchase', [
        'sku' => 'IPHONE-FLASH',
        'quantity' => 1,
    ])->assertConflict()->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
});
