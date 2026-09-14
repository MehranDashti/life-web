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
    public function __construct(private readonly ReportService $service) {}

    /**
     * The caller's own report subscriptions, filtered and paginated.
     */
    public function index(ReportFilter $filter): JsonResponse
    {
        return $this->successResponse(
            trans('messages.action_successfully_done'),
            $this->service->getFilter(
                filter: $filter,
                resource: ReportResource::class,
                // Ownership is a server-side condition; no query parameter reaches it.
                conditions: ['user_id' => $this->currentUser()->getKey()],
            ),
        );
    }

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

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        return $user;
    }
}
