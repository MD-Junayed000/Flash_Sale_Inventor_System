<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'price',
        'stock_quantity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'status' => ProductStatus::class,
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isInStock(int $quantity): bool
    {
        return $this->stock_quantity >= $quantity;
    }

    /**
     * Atomically decrement stock if sufficient.
     * Returns true on success, false if insufficient stock.
     */
    public function decrementStock(int $quantity): bool
    {
        $affected = static::query()
            ->where('id', $this->id)
            ->where('stock_quantity', '>=', $quantity)
            ->update([
                'stock_quantity' => \DB::raw('stock_quantity - ' . (int) $quantity),
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            $this->refresh();

            return true;
        }

        return false;
    }
}
