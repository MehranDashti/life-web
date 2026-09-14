<?php

declare(strict_types=1);

namespace App\Mediators\Contracts;

use App\Repositories\Contracts\BaseRepositoryInterface;

/**
 * Implemented by mediators that need to read persistence to decide a guard.
 */
interface HasRepositoryInterface
{
    public function repositoryClass(): BaseRepositoryInterface;
}
