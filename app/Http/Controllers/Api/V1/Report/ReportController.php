<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Report;

use Throwable;
use App\Models\User\User;
use App\Models\Report\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\DTO\Report\CreateReportDTO;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Services\Report\ReportService;
use App\Http\Filters\Report\ReportFilter;
use App\Http\Resources\Report\ReportResource;
use App\Http\Requests\Report\CreateReportRequest;

class ReportController extends Controller
{
    /**
     * One service per controller: the controller's only job is to translate between
     * HTTP and the domain.
     */
    public function __construct(private readonly ReportService $service) {}

    /**
     * The caller's own report subscriptions, filtered and paginated.
     *
     * Ownership is passed as a server-side condition, which no query parameter can
     * reach — client filters narrow within the caller's rows and can never widen
     * the set.
     */
    public function index(ReportFilter $filter): JsonResponse
    {
        return $this->successResponse(
            trans('messages.action_successfully_done'),
            $this->service->getFilter(
                filter: $filter,
                resource: ReportResource::class,
                conditions: ['user_id' => $this->currentUser()->getKey()],
            ),
        );
    }

    /**
     * Create a report subscription for the caller.
     *
     * Wrapped in a transaction because creating a report also writes its schedule;
     * a half-created subscription would be dispatched with no next run.
     */
    public function create(CreateReportRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $dto = app(CreateReportDTO::class)->fromRequest($request);
            $report = $this->service->createReport($dto);

            DB::commit();

            return $this->successResponse(
                trans('messages.report_created'),
                new ReportResource($report),
            );
        } catch (Throwable $exception) {
            DB::rollBack();

            return $this->failureResponse($exception->getMessage(), $exception);
        }
    }

    /**
     * Read one report the caller owns.
     *
     * Route-model binding resolves the record; ownership is checked in the service,
     * so an id belonging to someone else is a 403 rather than a leak.
     */
    public function view(Report $report): JsonResponse
    {
        try {
            return $this->successResponse(
                trans('messages.action_successfully_done'),
                new ReportResource($this->service->getOwnedReport($report, $this->currentUser())),
            );
        } catch (Throwable $exception) {
            return $this->failureResponse($exception->getMessage(), $exception);
        }
    }

    /**
     * The caller, resolved through the api guard.
     *
     * Always the api guard, never the bare Auth facade — the default guard is still
     * `web`, which would silently return null on every request.
     */
    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        return $user;
    }
}
