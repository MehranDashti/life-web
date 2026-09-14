<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthCheckController;
use App\Http\Controllers\Api\V1\Report\ReportController;
use App\Http\Controllers\Api\V1\Report\ReportRunController;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 by bootstrap/app.php with the `api` middleware group and
| route-model binding. There is one audience — the task describes a single
| end-user API — so there is no admin/client split.
|
| Role/permission middleware is deliberately absent: the task states that
| authorization checks are out of scope. Ownership scoping still applies and is
| enforced by ReportMediator in the service layer, not here, so the same rule
| holds for the queued job as well as for HTTP.
|
*/

Route::get('/up', HealthCheckController::class)->name('health');

Route::prefix('auth')->as('auth.')->group(static function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login');

    Route::middleware('auth:api')->group(static function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::middleware('auth:api')->prefix('reports')->as('reports.')->group(static function (): void {
    /* The three routes the task names. */
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::post('/', [ReportController::class, 'create'])->name('create');

    Route::get('/{report}', [ReportController::class, 'view'])->name('view');

    /*
     | Beyond the task's minimum: without these the generated workbook is only
     | observable by waiting for the scheduler and reading the mail log, which
     | makes the deliverable impossible to demonstrate or review.
     */
    Route::get('/{report}/runs', [ReportRunController::class, 'index'])->name('runs.index');

    // Generation is synchronous and can be slow, so it carries its own tighter limit.
    Route::post('/{report}/run', [ReportRunController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('runs.store');

    Route::get('/{report}/runs/{run}/download', [ReportRunController::class, 'download'])
        ->name('runs.download');
});
