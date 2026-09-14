<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthCheckController;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 by bootstrap/app.php with the `api` middleware group and
| route-model binding. There is one audience — the task describes a single
| end-user API, so there is no admin/client split.
|
| Role/permission middleware is deliberately absent: the task states that
| authorization checks are out of scope. Ownership scoping still applies and is
| enforced in the service layer, not here.
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
