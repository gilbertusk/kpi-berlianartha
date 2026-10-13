<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Application\HomeAssignment;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\EmployeeAssignment;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculationLine;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Illuminate\Support\Collection;

/**
 * The team page of a Team Leader or Department Head (PRD §9.1): members,
 * the state of their actuals, the team's target versus actual, and member
 * changes. Every method takes a unit the caller already authorized.
 */
final readonly class TeamOverviewQuery
{
    public function __construct(
        private AccessControl $access,
        private OrganizationDirectory $directory,
    ) {}

    /**
     * Teams whose review this account manages.
     *
     * @return list<UnitNode>
     */
    public function teams(User $user): array
    {
        $scope = $this->access->unitScope($user, Capability::TeamReviewManage);

        return array_values(array_filter(
            $this->directory->snapshot($this->access->businessToday())->ofType(UnitType::Team),
            static fn (UnitNode $unit): bool => $scope->coversUnit($unit->id),
        ));
    }

    /**
     * @return list<HomeAssignment>
     */
    public function members(int $unitId): array
    {
        return $this->directory->homeAssignmentsOn([$unitId], $this->access->businessToday());
    }

    /**
     * The newest period in progress or review that has targets for the unit.
     */
    public function currentPeriod(int $unitId): ?AssessmentPeriod
    {
        return AssessmentPeriod::query()
            ->whereIn('status', [PeriodStatus::InProgress->value, PeriodStatus::Review->value])
            ->whereIn('id', PeriodTarget::query()->where('organization_unit_id', $unitId)->select('assessment_period_id'))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Per employee of the unit: required targets by the least advanced state
     * of their actual entries. Two queries, whatever the team size.
     *
     * @return array<int, array{targets: int, missing: int, draft: int, submitted: int, rejected: int, verified: int}> keyed by employee id
     */
    public function memberActuals(AssessmentPeriod $period, int $unitId): array
    {
        $targets = PeriodTarget::query()
            ->where('assessment_period_id', $period->id)
            ->where('organization_unit_id', $unitId)
            ->whereNotNull('employee_id')
            ->where('is_required', true)
            ->get(['id', 'employee_id']);

        $versions = KpiActualVersion::query()
            ->join('kpi_actuals', 'kpi_actuals.id', '=', 'kpi_actual_versions.kpi_actual_id')
            ->whereIn('kpi_actuals.period_target_id', $targets->modelKeys())
            ->toBase()
            ->get(['kpi_actuals.period_target_id', 'kpi_actual_versions.status']);

        $states = [];

        foreach ($versions as $version) {
            $states[(int) $version->period_target_id][(string) $version->status] = true;
        }

        $result = [];

        foreach ($targets as $target) {
            $employeeId = (int) $target->employee_id;
            $result[$employeeId] ??= ['targets' => 0, 'missing' => 0, 'draft' => 0, 'submitted' => 0, 'rejected' => 0, 'verified' => 0];
            $result[$employeeId]['targets']++;
            $state = 'missing';

            foreach ([ActualStatus::Rejected, ActualStatus::Draft, ActualStatus::Submitted, ActualStatus::Verified] as $status) {
                if (isset($states[$target->id][$status->value])) {
                    $state = $status->value;

                    break;
                }
            }

            $result[$employeeId][$state]++;
        }

        return $result;
    }

    /**
     * Target versus actual of the team's own scorecard: the lines of its
     * latest calculation, so each figure is the one that was scored.
     *
     * @return array{calculation: ScorecardCalculation|null, lines: Collection<int, ScorecardCalculationLine>}
     */
    public function teamScorecard(AssessmentPeriod $period, int $unitId): array
    {
        $assignment = PeriodAssignment::query()
            ->where('assessment_period_id', $period->id)
            ->where('subject_type', SubjectType::Unit->value)
            ->where('organization_unit_id', $unitId)
            ->first();

        $calculation = $assignment === null ? null : ScorecardCalculation::query()
            ->where('period_assignment_id', $assignment->id)
            ->orderByDesc('sequence_number')
            ->first();

        return [
            'calculation' => $calculation,
            'lines' => $calculation === null
                ? new Collection
                : ScorecardCalculationLine::query()->where('scorecard_calculation_id', $calculation->id)->orderBy('line_number')->get(),
        ];
    }

    /**
     * Who joined or left the unit, newest first: names, positions, and
     * dates. The reasons HR recorded for a placement are not shown on the
     * team page; they stay with the accounts that manage employees.
     *
     * @return Collection<int, EmployeeAssignment>
     */
    public function memberHistory(int $unitId): Collection
    {
        return EmployeeAssignment::query()
            ->with('employee')
            ->where('organization_unit_id', $unitId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }
}
