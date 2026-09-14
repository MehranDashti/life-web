<?php

declare(strict_types=1);

namespace App\Services\Traits;

use RuntimeException;
use App\Mediators\Contracts\BaseMediatorInterface;

trait BaseServiceTrait
{
    protected ?BaseMediatorInterface $mediator = null;

    protected bool $ignoreMediator = false;

    /**
     * Let a trusted caller (a seeder, a backfill command) skip guard checks that
     * only make sense for user-originated requests.
     */
    public function setIgnoreMediator(bool $ignoreMediator): self
    {
        $this->ignoreMediator = $ignoreMediator;

        return $this;
    }

    public function getIgnoreMediator(): bool
    {
        return $this->ignoreMediator;
    }

    public function getMediator(): BaseMediatorInterface
    {
        if (! $this->mediator instanceof BaseMediatorInterface) {
            throw new RuntimeException(static::class.' has no mediator; implement HasMediatorInterface.');
        }

        return $this->mediator;
    }

    public function getServicePaginate(): int
    {
        return 15;
    }
}
