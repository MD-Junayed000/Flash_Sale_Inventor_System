<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local-only fixture data.
 *
 * Creates three canonical users (`alice@test.com`, `bob@test.com`,
 * `flash@test.com`), two products (one scarce, one plentiful) with a known
 * price, and one pre-existing completed order so the smoke test and the
 * docs example responses have a stable, reproducible baseline.
 *
 * Safe to re-run: every record is upserted by natural key (email / sku) so
 * `php artisan db:seed --force` is idempotent.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['alice@test.com', 'Alice Tester', 'password'],
            ['bob@test.com',   'Bob Tester',   'password'],
            ['flash@test.com', 'Flash Bot',    'password'],
        ] as [$email, $name, $password]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name'     => $name,
                    'password' => Hash::make($password),
                ],
            );
        }

        $products = [
            ['sku' => 'SKU-FLASH-001', 'name' => 'Limited Edition Sneaker',  'price' => 1999.00, 'stock_quantity' => 100, 'status' => 'active'],
            ['sku' => 'SKU-FLASH-002', 'name' => 'Collector Hoodie',         'price' =>  899.00, 'stock_quantity' =>  10, 'status' => 'active'],
        ];

        foreach ($products as $row) {
            Product::updateOrCreate(['sku' => $row['sku']], $row);
        }

        Order::updateOrCreate(
            ['user_email' => 'alice@test.com', 'sku' => 'SKU-FLASH-001'],
            [
                'product_id'          => 1,
                'quantity'            => 1,
                'unit_price'          => 1999.00,
                'discount_percentage' => 10,
                'payable_amount'      => 1799.10,
                'invoice_number'      => 'INV-DEMO-001',
                'status'              => 'completed',
            ],
        );
    }
}
