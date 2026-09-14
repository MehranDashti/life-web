<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\BaseRepositoryProvider;
use Laravel\Passport\PassportServiceProvider;
use Agog\Osmose\Providers\OsmoseServiceProvider;

return [
    /* Dependency providers */
    OsmoseServiceProvider::class,
    PassportServiceProvider::class,

    /* Application providers */
    AppServiceProvider::class,
    BaseRepositoryProvider::class,
];
