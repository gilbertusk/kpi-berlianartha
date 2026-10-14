<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;

/**
 * An authorized report request: who asks, the validated filters, the period
 * and unit they resolved to, and a description of the organization scope the
 * rows are limited to.
 */
final readonly class ReportContext
{
    /**
     * @param  list<int>|null  $unitIds  the filtered unit and everything below it; null = no unit filter
     */
    public function __construct(
        public User $user,
        public ReportFilters $filters,
        public ?AssessmentPeriod $period,
        public ?array $unitIds,
        public string $scopeSummary,
    ) {}
}
