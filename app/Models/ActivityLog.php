<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'sku',
        'quantity',
        'status',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActivityStatus::class,
            'quantity' => 'integer',
        ];
    }
}
