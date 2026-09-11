<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'user_email',
        'sku',
        'quantity',
        'unit_price',
        'discount_percentage',
        'payable_amount',
        'invoice_number',
        'status',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'discount_percentage' => 'integer',
            'status' => OrderStatus::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === OrderStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === OrderStatus::Failed;
    }
}
