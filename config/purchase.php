<?php

/**
 * Flash-sale-specific configuration.
 *
 * Every value can be overridden from .env without code changes so
 * tests can dial things down (sync queue, array cache, etc.).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Cooldown
    |--------------------------------------------------------------------------
    | A user (identified by their Sanctum-authenticated id) cannot purchase
    | the same SKU more than once within `cooldown_seconds`.
    |
    | `cooldown_store` defaults to the same store the application cache uses
    | (which is Redis in production). Tests use `array` for determinism.
    */
    'cooldown_seconds' => (int) env('PURCHASE_COOLDOWN_SECONDS', 60),
    'cooldown_store'   => env('PURCHASE_COOLDON_STORE', env('CACHE_STORE', 'redis')),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Which queue connection / queue name should ProcessOrder jobs go to.
    | In production this MUST be redis (or rabbitmq) for throughput.
    */
    'queue_connection' => env('PURCHASE_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
    'queue_name'       => env('PURCHASE_QUEUE_NAME', env('QUEUE_NAME', 'orders')),

    /*
    |--------------------------------------------------------------------------
    | Retry policy (DLQ)
    |--------------------------------------------------------------------------
    | Number of attempts and exponential backoff (in seconds).
    | After max retries the job's `failed()` hook fires and the order is
    | marked FAILED with the failure reason persisted.
    */
    'max_retries' => (int) env('PURCHASE_MAX_RETRIES', 3),
    'backoff'     => array_map('intval', explode(',', (string) env('PURCHASE_BACKOFF', '5,15,60'))),

    /*
    |--------------------------------------------------------------------------
    | Mystery Discount distribution
    |--------------------------------------------------------------------------
    | Relative weights. The implementation picks a random int between 0 and
    | (sum of weights) and maps it to a discount bucket. Defaults to 75/20/5
    | which yields 0% / 10% / 50% respectively.
    */
    'discount_weights' => [
        'none'   => (int) env('PURCHASE_DISCOUNT_WEIGHT_NONE', 75),
        'ten'    => (int) env('PURCHASE_DISCOUNT_WEIGHT_TEN', 20),
        'fifty'  => (int) env('PURCHASE_DISCOUNT_WEIGHT_FIFTY', 5),
    ],
    'discount_values' => [
        'none'  => 0,
        'ten'   => 10,
        'fifty' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (defence in depth alongside the per-user cooldown)
    |--------------------------------------------------------------------------
    | Per-user per-minute and per-IP per-minute limits on POST /purchase.
    | Rotating-email attacks get throttled at the IP layer.
    */
    'rate_limit' => [
        'per_minute'      => (int) env('PURCHASE_RATE_LIMIT_PER_MIN', 30),
        'per_ip_per_min'  => (int) env('PURCHASE_RATE_LIMIT_PER_IP_PER_MIN', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    | Idempotency keys are cached for this long so a retry within the
    | window returns the same response without re-processing.
    */
    'idempotency_ttl' => (int) env('PURCHASE_IDEMPOTENCY_TTL', 24 * 60 * 60),
];
