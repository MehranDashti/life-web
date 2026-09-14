<?php

declare(strict_types=1);

namespace App\Mediators\Report;

use RuntimeException;
use App\Models\User\User;
use App\Models\Report\Report;
use Illuminate\Http\Response;
use App\Models\Report\ReportRun;
use App\Mediators\Contracts\BaseMediator;

/**
 * Business-rule guards for the report domain.
 *
 * This project deliberately has no role/permission system — the task states that
 * authorization checks are out of scope. Ownership is different: a report belongs
 * to exactly one user, and serving one user's data to another is a data-integrity
 * defect, not a policy decision. It is enforced here, in the layer that exists for
 * invariants, so the same rule applies from HTTP and from a queued job alike.
 */
final class ReportMediator extends BaseMediator
{
    public function assertOwnedBy(Report $report, User $user): self
    {
        $this->checkOwnership($report, $user->getKey());

        return $this;
    }

    public function assertRunBelongsTo(ReportRun $run, Report $report): self
    {
        if ($run->report_id !== $report->getKey()) {
            throw new RuntimeException(trans('messages.model_not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this;
    }

    /**
     * A run only has a file once it has succeeded; asking to download anything
     * else is a 404, not an empty body.
     */
    public function assertRunHasFile(ReportRun $run): self
    {
        if (! $run->hasFile()) {
            throw new RuntimeException(trans('messages.report_file_not_ready'), Response::HTTP_NOT_FOUND);
        }

        return $this;
    }
}
