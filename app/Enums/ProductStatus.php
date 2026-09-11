<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
