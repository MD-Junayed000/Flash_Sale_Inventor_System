<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
