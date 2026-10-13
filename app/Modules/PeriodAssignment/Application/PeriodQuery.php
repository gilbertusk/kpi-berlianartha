<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * Scoped reads for periods and their assignments. A draft period shows the
 * plan the snapshot would contain today; an opened period shows the stored
 * snapshot. Both are filtered by the viewer's unit scope.
 */
final readonly class PeriodQuery
{
    private const PER_PAGE = 25;

    public function __construct(
        private PeriodVisibility $visibility,
        private AssignmentSnapshotBuilder $snapshots,
    ) {}

    /**
     * @return LengthAwarePaginator<int, AssessmentPeriod>
     */
    public function periods(UnitScope $scope, ?string $status, string $businessToday, int $page): LengthAwarePaginator
    {
        $query = AssessmentPeriod::query()->with(['scopeUnit', 'deadlines'])->orderByDesc('starts_on')->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        // Periods are few (a handful per year), so visibility is decided in
        // PHP against the hierarchy instead of a recursive SQL filter.
        $visible = $query->get()
            ->filter(fn (AssessmentPeriod $period): bool => $this->visibility->concerns($scope, $period, $businessToday))
            ->values();

        return new Paginator($visible->forPage($page, self::PER_PAGE)->values(), $visible->count(), self::PER_PAGE, $page, ['pageName' => 'page']);
    }

    /**
     * @return array{rows: LengthAwarePaginator<int, AssignmentView>, summary: array{employees: int, units: int, covered: int, not_covered: int, approver_gaps: int}, preview: bool}
     */
    public function assignments(AssessmentPeriod $period, UnitScope $scope, ?string $subjectType, ?string $coverage, bool $gapsOnly, string $search, int $page, CarbonImmutable $at): array
    {
        $needle = mb_strtolower(trim($search));

        if ($period->status === PeriodStatus::Draft) {
            return $this->preview($period, $scope, $subjectType, $coverage, $gapsOnly, $needle, $page, $at);
        }

        $base = PeriodAssignment::query()->where('assessment_period_id', $period->id);
        $this->applyScope($base, $scope);

        $summary = ['employees' => 0, 'units' => 0, 'covered' => 0, 'not_covered' => 0, 'approver_gaps' => 0];

        foreach ((clone $base)->get(['id', 'subject_type', 'coverage', 'approver_gaps']) as $row) {
            $summary[$row->subject_type === SubjectType::Employee ? 'employees' : 'units']++;
            $summary[$row->coverage === Coverage::Covered ? 'covered' : 'not_covered']++;

            if ($row->coverage === Coverage::Covered && $row->approver_gaps !== null) {
                $summary['approver_gaps']++;
            }
        }

        if ($subjectType !== null) {
            $base->where('subject_type', $subjectType);
        }

        if ($coverage !== null) {
            $base->where('coverage', $coverage === Coverage::Covered->value ? '=' : '<>', Coverage::Covered->value);
        }

        if ($gapsOnly) {
            $base->whereNotNull('approver_gaps');
        }

        if ($needle !== '') {
            $pattern = '%'.addcslashes($needle, '%_\\').'%';
            $base->where(fn (Builder $match) => $match
                ->whereRaw('lower(subject_code) like ?', [$pattern])
                ->orWhereRaw('lower(subject_name) like ?', [$pattern]));
        }

        $rows = $base->with('approvers')
            ->orderBy('unit_code')
            ->orderBy('subject_type')
            ->orderBy('subject_code')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page)
            ->through(static fn (PeriodAssignment $assignment): AssignmentView => AssignmentView::fromSnapshot($assignment));

        return ['rows' => $rows, 'summary' => $summary, 'preview' => false];
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function auditTrail(AssessmentPeriod $period, int $page): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', 'assessment_period')
            ->where('entity_id', $period->public_id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'auditPage', $page);
    }

    /**
     * @return array{rows: LengthAwarePaginator<int, AssignmentView>, summary: array{employees: int, units: int, covered: int, not_covered: int, approver_gaps: int}, preview: bool}
     */
    private function preview(AssessmentPeriod $period, UnitScope $scope, ?string $subjectType, ?string $coverage, bool $gapsOnly, string $needle, int $page, CarbonImmutable $at): array
    {
        $plan = array_values(array_filter(
            $this->snapshots->plan($period, $at),
            static fn (PlannedAssignment $planned): bool => $scope->coversUnit($planned->unitId),
        ));

        $views = collect($plan)
            ->map(static fn (PlannedAssignment $planned): AssignmentView => AssignmentView::fromPlan($planned))
            ->filter(static fn (AssignmentView $view): bool => $view->matches($subjectType, $coverage, $gapsOnly, $needle))
            ->sortBy(static fn (AssignmentView $view): array => [$view->unitLabel, $view->subjectType->value, $view->subjectCode])
            ->values();

        return [
            'rows' => new Paginator($views->forPage($page, self::PER_PAGE)->values(), $views->count(), self::PER_PAGE, $page, ['pageName' => 'page']),
            'summary' => PeriodService::planSummary($plan),
            'preview' => true,
        ];
    }

    /**
     * @param  Builder<PeriodAssignment>  $query
     */
    private function applyScope(Builder $query, UnitScope $scope): void
    {
        if (! $scope->all) {
            $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
        }
    }
}
