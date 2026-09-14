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

    /**
     * Whether guard checks are currently being skipped.
     */
    public function getIgnoreMediator(): bool
    {
        return $this->ignoreMediator;
    }

    /**
     * The domain's guard object, or a clear failure if the service never declared
     * one — a missing mediator is a wiring mistake, not a runtime condition.
     */
    public function getMediator(): BaseMediatorInterface
    {
        if (! $this->mediator instanceof BaseMediatorInterface) {
            throw new RuntimeException(static::class.' has no mediator; implement HasMediatorInterface.');
        }

        return $this->mediator;
    }

    /**
     * Page size used when the request does not specify one.
     */
    public function getServicePaginate(): int
    {
        return 15;
    }
}
