<?php

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\IdempotencyKeyMiddleware;
use App\Http\Middleware\ThrottlePurchase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        /*
        |----------------------------------------------------------------------
        | Global middleware — runs for every request (web + api)
        |----------------------------------------------------------------------
        |
        | CorrelationId runs first so EVERY downstream log line (jobs, DB
        | listeners, exception handler) can carry the request id.
        |
        */
        $middleware->prepend(CorrelationIdMiddleware::class);

        /*
        |----------------------------------------------------------------------
        | Aliases — referenced as `middleware:alias` in routes
        |----------------------------------------------------------------------
        */
        $middleware->alias([
            'throttle.purchase' => ThrottlePurchase::class,
            'idempotency'       => IdempotencyKeyMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
