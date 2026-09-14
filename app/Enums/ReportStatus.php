<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportStatus: string
{
    case Active = 'active';

    /**
     * Set automatically after repeated consecutive failures. A subscription that
     * fails every tick forever is a self-inflicted denial of service against the
     * search cluster; pausing it converts an unbounded retry storm into a visible,
     * inspectable state.
     */
    case Paused = 'paused';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
