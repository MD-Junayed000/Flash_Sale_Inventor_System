<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Product::create([
            'name' => 'Flash Sale T-Shirt',
            'sku' => 'SKU-1001',
            'price' => 1000.00,
            'stock_quantity' => 5,
            'status' => ProductStatus::Active,
        ]);

        Product::create([
            'name' => 'Flash Sale Sneakers',
            'sku' => 'SKU-1002',
            'price' => 2500.00,
            'stock_quantity' => 50,
            'status' => ProductStatus::Active,
        ]);

        Product::create([
            'name' => 'Flash Sale Backpack (Inactive)',
            'sku' => 'SKU-1003',
            'price' => 1500.00,
            'stock_quantity' => 20,
            'status' => ProductStatus::Inactive,
        ]);
    }
}
