<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Order;
use App\Models\Product;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['purchase.cooldown_seconds' => 0]);
    Product::create([
        'sku' => 'FLASH-IDEMP',
        'name' => 'Flash Sale Item',
        'price' => 199.00,
        'stock_quantity' => 10,
        'status' => 'active',
    ]);
});

it('replays a successful response for the same idempotency key', function (): void {
    ['token' => $token] = $this->registerAndLogin();
    $headers = ['Idempotency-Key' => 'idem-'.bin2hex(random_bytes(8))];
    $body = ['sku' => 'FLASH-IDEMP', 'quantity' => 1];

    $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/purchase', $body)
        ->assertCreated();
    $second = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/purchase', $body)
        ->assertCreated();

    expect($second->json('data.order_id'))->toBe($first->json('data.order_id'));
    expect($second->headers->get('Idempotent-Replay'))->toBe('true');
    expect(Product::where('sku', 'FLASH-IDEMP')->value('stock_quantity'))->toBe(9);
    expect(Order::where('sku', 'FLASH-IDEMP')->count())->toBe(1);
});
