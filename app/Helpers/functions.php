<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;

if (! function_exists('getRealIp')) {
    /**
     * Resolve the caller's real IP, honouring the proxy headers Octane/Nginx pass through.
     */
    function getRealIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
            $value = $_SERVER[$header] ?? null;

            if (is_string($value) && $value !== '') {
                return trim(explode(',', $value)[0]);
            }
        }

        return Request::ip();
    }
}

if (! function_exists('isoDate')) {
    /**
     * Format a date-ish value as ISO-8601, or null when absent. Every API response
     * serialises timestamps through this helper so the wire format never drifts.
     */
    function isoDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
