<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\User\User;
use App\Models\Report\Report;
use App\Models\Report\ReportRun;
use App\Services\Traits\WebFilter;
use App\DTO\Report\CreateReportDTO;
use Agog\Osmose\Library\OsmoseFilter;
use App\Services\Contracts\BaseService;
use App\Services\Traits\QueryCacheable;
use Illuminate\Database\Eloquent\Model;
use App\Mediators\Report\ReportMediator;
use App\Http\Resources\Report\ReportResource;
use App\Http\Resources\Report\ReportRunResource;
use App\Services\Contracts\DataServiceInterface;
use App\Services\Contracts\HasMediatorInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Mediators\Contracts\BaseMediatorInterface;
use App\Repositories\Contracts\Report\ReportRepositoryInterface;
use App\Repositories\Contracts\Report\ReportRunRepositoryInterface;

/**
 * Report subscriptions: creating them, listing them, and reading one back.
 *
 * Execution lives in ReportGenerationService — this service owns the
 * subscription, not the work it triggers.
 */
final class ReportService extends BaseService implements DataServiceInterface, HasMediatorInterface
{
    use QueryCacheable, WebFilter;

    private const string CACHE_TAG = 'reports';

    /**
     * Two repositories: subscriptions through the base contract, and runs
     * separately, because listing a report's history is a read of a different table.
     */
    public function __construct(
        ReportRepositoryInterface $repository,
        private readonly ReportRunRepositoryInterface $runs,
    ) {
        parent::__construct($repository);
    }

    /**
     * The domain's guard object, resolved by BaseService at construction.
     */
    public function mediatorClass(): BaseMediatorInterface
    {
        return app(ReportMediator::class);
    }

    /**
     * Persist a new subscription and invalidate the owner's cached list, so their
     * next read reflects it immediately rather than after the TTL.
     */
    public function createReport(CreateReportDTO $dto): Report
    {
        /** @var Report $report */
        $report = $this->repository->create($dto->toArray());

        $this->flushQueryCache(self::CACHE_TAG);

        return $report;
    }

    /**
     * The caller's own reports, filtered and paginated.
     *
     * Ownership goes through addListConditions(), which client query parameters
     * cannot reach — a filter narrows within the caller's rows and can never
     * widen the set.
     *
     * @param  class-string<JsonResource>|null  $resource
     * @param  array<string, mixed>  $conditions
     * @return array{list: mixed, pagination: array{total: int, current: int, page_size: int}}
     */
    public function getFilter(OsmoseFilter $filter, ?Model $model = null, ?string $resource = null, array $conditions = []): array
    {
        return $this->rememberQueryCache(
            self::CACHE_TAG,
            $this->queryCacheKey(self::CACHE_TAG),
            fn (): array => $this->filter($filter, $model ?? $this->repository->getModel())
                ->addListConditions($conditions)
                ->sortModel($this->queryInfo()['sort'])
                ->orderBy('created_at', 'DESC')
                ->paginate()
                ->renderFilter($resource ?? ReportResource::class),
        );
    }

    /**
     * Run history for one report.
     *
     * @param  array<string, mixed>  $conditions
     * @return array{list: mixed, pagination: array{total: int, current: int, page_size: int}}
     */
    public function getRunFilter(OsmoseFilter $filter, Report $report, array $conditions = []): array
    {
        return $this->filter($filter, $this->runs->getModel())
            ->addListConditions(array_merge($conditions, ['report_id' => $report->getKey()]))
            ->sortModel($this->queryInfo()['sort'])
            ->orderBy('created_at', 'DESC')
            ->paginate()
            ->renderFilter(ReportRunResource::class);
    }

    /**
     * Resolve a report the caller owns, or throw.
     */
    public function getOwnedReport(Report $report, User $user): Report
    {
        $this->reportMediator()->assertOwnedBy($report, $user);

        return $report;
    }

    /**
     * Resolve a run belonging to a report the caller owns, or throw.
     */
    public function getOwnedRun(Report $report, ReportRun $run, User $user): ReportRun
    {
        $this->reportMediator()
            ->assertOwnedBy($report, $user)
            ->assertRunBelongsTo($run, $report);

        return $run;
    }

    /**
     * Resolve a run the caller owns that actually produced a file.
     *
     * Ownership and existence are checked before the artifact, so a run belonging to
     * someone else is refused rather than probed for a file.
     */
    public function getDownloadableRun(Report $report, ReportRun $run, User $user): ReportRun
    {
        $this->getOwnedRun($report, $run, $user);
        $this->reportMediator()->assertRunHasFile($run);

        return $run;
    }

    /**
     * The domain's guards, narrowed from the base contract so the report-specific
     * assertions are reachable without a cast at every call site.
     */
    private function reportMediator(): ReportMediator
    {
        /** @var ReportMediator $mediator */
        $mediator = $this->getMediator();

        return $mediator;
    }
}
