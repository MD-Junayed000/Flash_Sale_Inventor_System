<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Services\DiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Distribution invariant test: a large number of attempts must surface each
 * discount tier with a frequency close to the configured weights.
 *
 *   *  75% no discount
 *   *  20% 10% off
 *   *   5% 50% off
 *
 * We use 10,000 iterations and a generous ±3 pp margin to keep the test
 * deterministic under CI's load variance.
 */
uses(RefreshDatabase::class);

it('discountService distribution matches configured weights within ±3pp', function () {
    /** @var DiscountService $svc */
    $svc = app(DiscountService::class);

    $weights = config('purchase.discount_weights');
    expect($weights)->toMatchArray([
        'none'  => expect()->toBeInt(),
        'ten'   => expect()->toBeInt(),
        'fifty' => expect()->toBeInt(),
    ]);

    $buckets = ['none' => 0, 'ten' => 0, 'fifty' => 0];

    // 10 000 iterations – < 1 s on a typical laptop.
    for ($i = 0; $i < 10_000; $i++) {
        $pct = $svc->roll();
        if ($pct === 0) {
            $buckets['none']++;
        } elseif ($pct === 10) {
            $buckets['ten']++;
        } elseif ($pct === 50) {
            $buckets['fifty']++;
        }
    }

    $total = array_sum($buckets);
    expect($total)->toBe(10_000);

    foreach ($weights as $key => $expected) {
        $actual = ($buckets[$key] / $total) * 100;
        // ±3 pp tolerance
        expect($actual)->toBeGreaterThanOrEqual($expected - 3);
        expect($actual)->toBeLessThanOrEqual($expected + 3);
    }
});

it('returns a value in the {0, 10, 50} set only', function () {
    $svc = app(DiscountService::class);
    for ($i = 0; $i < 1000; $i++) {
        $pct = $svc->roll();
        expect($pct)->toBeIn([0, 10, 50]);
    }
});
