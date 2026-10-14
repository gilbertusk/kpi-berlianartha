<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Scoring\Application\ScorecardQuery;
use App\Modules\Scoring\Application\ScorePeriodAccess;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reports read through the scorecard scope: unit scorecards in the
 * `scorecard.view` scope, employee scorecards in the
 * `individual_scorecard.view` scope. The "own scorecard" exception of an
 * employee does not apply: reports are for accounts that read others.
 * This is the scope the scorecard pages use, so report and screen agree.
 */
abstract class ScorecardBasedReport implements ReportDefinition
{
    protected const CHUNK = 200;

    public function __construct(
        protected readonly ReportSupport $support,
        protected readonly ScorePeriodAccess $scoreAccess,
        protected readonly ScorecardQuery $scorecards,
    ) {}

    public function statuses(): array
    {
        return [];
    }

    public function available(User $user): bool
    {
        return $this->support->mayUseReports($user) && $this->scope($user)->reachesOthers();
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        $this->support->assertMayUseReports($user);
        $scope = $this->scope($user);

        if (! $scope->reachesOthers()) {
            throw new AuthorizationException;
        }

        $period = $this->support->period($filters);

        if (! $this->scoreAccess->canView($user, $period)) {
            throw new AuthorizationException;
        }

        return new ReportContext(
            $user,
            $filters,
            $period,
            $this->support->units($filters, $scope->units, $scope->individuals),
            $this->support->describe($filters, $scope->units, $scope->individuals),
        );
    }

    protected function scope(User $user): ScoreAccess
    {
        $scope = $this->scoreAccess->viewScope($user);

        return new ScoreAccess($scope->units, $scope->individuals, null);
    }

    /**
     * Scorecard subjects of the period inside scope and filters.
     *
     * @return Builder<PeriodAssignment>
     */
    protected function assignments(ReportContext $context): Builder
    {
        $filters = $context->filters;
        $query = $this->scorecards->base($context->period ?? throw new AuthorizationException, $this->scope($context->user));

        if ($context->unitIds !== null) {
            $query->whereIn('organization_unit_id', $context->unitIds);
        }

        if ($filters->level === 'employee') {
            $query->where('subject_type', SubjectType::Employee->value);
        } elseif ($filters->level !== null) {
            $query->where('subject_type', SubjectType::Unit->value)->where('unit_type', $filters->level);
        }

        if ($filters->employee !== null) {
            $query->where('subject_type', SubjectType::Employee->value)->where('subject_code', $filters->employee);
        }

        return $query;
    }

    /**
     * Latest calculation per assignment, by one query per chunk.
     *
     * @param  list<int>  $assignmentIds
     * @return array<int, ScorecardCalculation>
     */
    protected static function latestCalculations(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        $latest = [];

        /** @var Collection<int, ScorecardCalculation> $calculations */
        $calculations = ScorecardCalculation::query()
            ->whereIn('period_assignment_id', $assignmentIds)
            ->whereNotExists(fn ($newer) => $newer
                ->from('scorecard_calculations as newer')
                ->whereColumn('newer.period_assignment_id', 'scorecard_calculations.period_assignment_id')
                ->whereColumn('newer.sequence_number', '>', 'scorecard_calculations.sequence_number'))
            ->get();

        foreach ($calculations as $calculation) {
            $latest[$calculation->period_assignment_id] = $calculation;
        }

        return $latest;
    }
}
