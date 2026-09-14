<?php

declare(strict_types=1);

namespace App\Services\Contracts;

interface BaseServiceInterface
{
    /**
     * Default page size used when the request does not specify one.
     */
    public function getServicePaginate(): int;
}
