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
    /**
     * The mediator guarding this domain. BaseService resolves it at construction.
     */
    public function mediatorClass(): BaseMediatorInterface;
}
