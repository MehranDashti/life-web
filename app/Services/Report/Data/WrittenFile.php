<?php

declare(strict_types=1);

namespace App\Services\Report\Data;

final readonly class WrittenFile
{
    /**
     * A written workbook: its path relative to the reports disk, and its size.
     */
    public function __construct(
        public string $path,
        public int $size,
    ) {}
}
