<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Mediators\Contracts\BaseMediatorInterface;

/**
 * Implemented by services whose domain has business-rule guards; BaseService
 * resolves the mediator automatically at construction.
 */
interface HasMediatorInterface
{
    public function mediatorClass(): BaseMediatorInterface;
}
