<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Account state. Only `active` accounts may authenticate.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
