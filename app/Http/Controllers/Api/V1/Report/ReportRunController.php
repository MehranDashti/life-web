<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Report;

use Throwable;
use App\Models\User\User;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use App\Jobs\GenerateReportJob;
use App\Models\Report\ReportRun;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Services\Report\ReportService;
use Illuminate\Support\Facades\Storage;
use App\Http\Filters\Report\ReportRunFilter;
use App\Http\Requests\Report\RunReportRequest;
use App\Http\Resources\Report\ReportRunResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inspecting and triggering report executions.
 *
 * These routes go beyond the three the task names. They exist because the
 * deliverable — an Excel histogram — is otherwise only observable by waiting for
 * the scheduler and reading the mail log, which makes the feature impossible to
 * demonstrate or review.
 */
class ReportRunController extends Controller
{
    /**
     * One service per controller: the controller's only job is to translate between
     * HTTP and the domain.
     */
    public function __construct(private readonly ReportService $service) {}

    /**
     * Execution history for one report.
     */
    public function index(Report $report, ReportRunFilter $filter): JsonResponse
    {
        try {
            $owned = $this->service->getOwnedReport($report, $this->currentUser());

            return $this->successResponse(
                trans('messages.action_successfully_done'),
                $this->service->getRunFilter($filter, $owned),
            );
        } catch (Throwable $exception) {
            return $this->failureResponse($exception->getMessage(), $exception);
        }
    }

    /**
     * Generate now, rather than waiting for the next scheduled tick.
     *
     * Runs through exactly the same job the scheduler dispatches — including the
     * same idempotency claim — so this is a trigger, not a second code path.
     */
    public function store(Report $report, RunReportRequest $request): JsonResponse
    {
        try {
            $owned = $this->service->getOwnedReport($report, $this->currentUser());

            [$from, $to] = $this->resolveWindow($owned, $request);

            dispatch_sync(new GenerateReportJob(
                reportId: (string) $owned->getKey(),
                periodStart: $from->toIso8601String(),
                periodEnd: $to->toIso8601String(),
            ));

            $run = $owned->runs()
                ->where('period_start', $from)
                ->where('period_end', $to)
                ->first();

            if (! $run instanceof ReportRun) {
                return $this->failureResponse(trans('messages.report_run_not_created'));
            }

            return $this->successResponse(
                trans('messages.report_generated'),
                new ReportRunResource($run),
            );
        } catch (Throwable $exception) {
            return $this->failureResponse($exception->getMessage(), $exception);
        }
    }

    /**
     * Stream the generated workbook.
     */
    public function download(Report $report, ReportRun $run): StreamedResponse|JsonResponse
    {
        try {
            $owned = $this->service->getOwnedReport($report, $this->currentUser());
            $downloadable = $this->service->getDownloadableRun($owned, $run, $this->currentUser());

            return Storage::disk('reports')->download(
                (string) $downloadable->file_path,
                $this->downloadName($owned, $downloadable),
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            );
        } catch (Throwable $exception) {
            return $this->failureResponse($exception->getMessage(), $exception);
        }
    }

    /**
     * The window a run should cover.
     *
     * With no explicit `from`/`to` this returns exactly what the scheduler would
     * have used, so an on-demand run and a scheduled one produce the same window
     * and therefore collide on the same idempotency claim.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(Report $report, RunReportRequest $request): array
    {
        $timezone = (string) config('search.histogram.timezone', 'Asia/Tehran');

        if ($request->filled('from') && $request->filled('to')) {
            return [
                Carbon::parse((string) $request->input('from'), $timezone)->startOfDay(),
                Carbon::parse((string) $request->input('to'), $timezone)->endOfDay(),
            ];
        }

        $now = Carbon::now();

        return [$report->period->windowStart($now), $report->period->windowEnd($now)];
    }

    /**
     * A human-readable filename for the download.
     *
     * The stored path is opaque and namespaced by report id; this is what the user
     * actually sees in their downloads folder.
     */
    private function downloadName(Report $report, ReportRun $run): string
    {
        return sprintf(
            '%s-%s-%s.xlsx',
            str($report->name)->slug()->value() ?: 'report',
            $run->period_start->format('Ymd'),
            $run->period_end->format('Ymd'),
        );
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
