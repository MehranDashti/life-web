<?php

declare(strict_types=1);

namespace App\Mediators\Contracts;

/**
 * Marker for the business-rule guard layer.
 *
 * A mediator answers "may this happen?" and throws when the answer is no; it never
 * performs the action itself. Add one only when a domain has a real invariant to
 * enforce — most domains never need one.
 */
interface BaseMediatorInterface {}
