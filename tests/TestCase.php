<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\CreatesApplication;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Helper: register and authenticate a user via the v1 API and return
     * the issued Sanctum token so individual tests can stay one-liner clean.
     *
     * @return array{user: array<string,mixed>, token: string}
     */
    protected function registerAndLogin(string $email = 'test@example.com', string $password = 'password'): array
    {
        $this->postJson('/api/v1/auth/register', [
            'name'     => 'Test User',
            'email'    => $email,
            'password' => $password,
        ])->assertOk();

        $login = $this->postJson('/api/v1/auth/login', [
            'email'    => $email,
            'password' => $password,
        ])->assertOk();

        return [
            'user'  => $login->json('user'),
            'token' => $login->json('token'),
        ];
    }

    /** Build a purchase JSON body with sane defaults. */
    protected function purchaseBody(string $sku = 'IPHONE-FLASH', int $quantity = 1): array
    {
        return [
            'sku'      => $sku,
            'quantity' => $quantity,
        ];
    }
}
