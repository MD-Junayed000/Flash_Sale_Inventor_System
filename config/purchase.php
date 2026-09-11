<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Purchase Cooldown Window
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) the same user must wait between purchases of the
    | same SKU. Set to 0 to disable the cooldown.
    |
    | Override at runtime with the PURCHASE_COOLDOWN_SECONDS environment
    | variable (e.g. for load tests you can shorten it to 1s).
    |
    */

    'cooldown_seconds' => (int) env('PURCHASE_COOLDOWN_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Cache Store for Cooldown Keys
    |--------------------------------------------------------------------------
    |
    | Which cache store should hold the cooldown lock? Defaults to the
    | application's default store. In production you'd typically point this
    | at Redis so the lock is shared across multiple web workers.
    |
    */

    'cooldown_store' => env('PURCHASE_COOLDOWN_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Discount Configuration
    |--------------------------------------------------------------------------
    |
    | Mystery discount distribution. Weights must be non-negative integers
    | and are relative; 75/20/5 means ~75% no discount, ~20% ten-percent off,
    | ~5% fifty-percent off.
    |
    */

    'discount_weights' => [
        0  => (int) env('PURCHASE_DISCOUNT_WEIGHT_NONE', 75),
        10 => (int) env('PURCHASE_DISCOUNT_WEIGHT_TEN', 20),
        50 => (int) env('PURCHASE_DISCOUNT_WEIGHT_FIFTY', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Queue Connection / Queue
    |--------------------------------------------------------------------------
    |
    | ProcessOrder jobs are dispatched here. Defaults to the framework
    | default but can be overridden for isolation in production.
    |
    */

    'queue_connection' => env('PURCHASE_QUEUE_CONNECTION', null),
    'queue_name'       => env('PURCHASE_QUEUE_NAME', null),
];
