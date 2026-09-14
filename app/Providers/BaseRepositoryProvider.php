<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\User\UserRepository;
use App\Repositories\Report\ReportRepository;
use App\Repositories\Report\ReportRunRepository;
use App\Repositories\Contracts\User\UserRepositoryInterface;
use App\Repositories\Contracts\Report\ReportRepositoryInterface;
use App\Repositories\Contracts\Report\ReportRunRepositoryInterface;

/**
 * The single source of truth for repository dependency injection.
 *
 * Every new repository interface MUST be bound here — a missing binding only
 * fails at runtime, when the container cannot resolve the interface.
 */
class BaseRepositoryProvider extends ServiceProvider
{
    public function register(): void
    {
        /* User section */
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);

        /* Report section */
        $this->app->bind(ReportRepositoryInterface::class, ReportRepository::class);
        $this->app->bind(ReportRunRepositoryInterface::class, ReportRunRepository::class);
    }
}
