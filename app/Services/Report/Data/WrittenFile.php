<?php

declare(strict_types=1);

namespace App\Services\Report\Data;

final readonly class WrittenFile
{
    public function __construct(
        public string $path,
        public int $size,
    ) {}
}
