<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\IdentityAccess\Application\RoleHolder;
use App\Modules\IdentityAccess\Application\RoleHolderDirectory;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\KpiGovernance\Application\TemplateResolver;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateItem;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\OrganizationSnapshot;
use App\Modules\Organization\Application\UnitNode;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/**
 * Works out who is assessed by whom in a period, as of the period's
 * assignment reference date:
 *
 * - every employee whose home team is inside the period scope, with the
 *   team, position, and hierarchy of that date;
 * - every unit inside the scope (unit scorecard);
 * - the template version in force on that date (position-specific template
 *   first, then the unit's template for all positions);
 * - the accounts holding the role of each approval stage for the subject's
 *   unit. Roles are read on the reference date, or on the day the plan is
 *   made when the reference date is already past, so an approver whose
 *   role has ended is never recorded and their successor is. The assessed employee is never their own approver; a stage without
 *   an approver is reported as a gap, never filled by assumption (DEC-002).
 *
 * Temporary assignments do not create a second scorecard.
 */
final class AssignmentSnapshotBuilder
{
    /** @var array<string, KpiTemplateVersion|null> */
    private array $templateCache = [];

    /** @var array<int, bool> template version id => every pinned KPI is in force */
    private array $kpiInForceCache = [];

    public function __construct(
        private readonly OrganizationDirectory $directory,
        private readonly RoleHolderDirectory $roleHolders,
        private readonly TemplateResolver $templates,
    ) {}

    /**
     * @return list<PlannedAssignment>
     */
    public function plan(AssessmentPeriod $period, CarbonImmutable $at): array
    {
        $this->templateCache = [];
        $this->kpiInForceCache = [];

        $referenceDate = $period->assignment_reference_date;
        $snapshot = $this->directory->snapshot($referenceDate);
        $unitIds = $snapshot->subtreeIds($period->organization_unit_id);

        if ($unitIds === []) {
            throw new BusinessRuleViolation('scope_unit_inactive', 'Unit scope periode tidak aktif pada tanggal acuan penugasan.');
        }

        $holders = $this->roleHolders->activeHolders(
            array_map(static fn (ApprovalStage $stage): Role => Role::from($stage->roleKey()), ApprovalStage::cases()),
            max($referenceDate, (new BusinessTime($period->timezone))->businessDate($at)),
            $at,
        );

        $planned = [];
        $seenEmployees = [];

        foreach ($this->directory->homeAssignmentsOn($unitIds, $referenceDate) as $home) {
            $unit = $snapshot->unit($home->unitId);

            // One scorecard per employee, even if the master data ever held
            // two home teams for the same date.
            if ($unit === null || isset($seenEmployees[$home->employeeId])) {
                continue;
            }

            $seenEmployees[$home->employeeId] = true;

            $template = $this->template($unit->id, SubjectType::Employee, $home->positionTitle, $referenceDate);
            [$approvers, $gaps] = $this->approvers($holders, $snapshot, $unit->id, $home->userId);

            $planned[] = new PlannedAssignment(
                subjectType: SubjectType::Employee,
                employeeId: $home->employeeId,
                unitId: $unit->id,
                sourceAssignmentId: $home->assignmentId,
                subjectCode: $home->employeeNumber,
                subjectName: $home->fullName,
                positionTitle: $home->positionTitle,
                unitCode: $unit->code,
                unitName: $unit->name,
                unitType: $unit->type->value,
                unitPath: self::pathOf($snapshot, $unit->id),
                templateVersionId: $template?->id,
                templateCode: $template?->template->code,
                templateVersionNumber: $template?->version_number,
                coverage: $this->coverage($template, $referenceDate),
                approvers: $approvers,
                approverGaps: $gaps,
            );
        }

        $units = array_values(array_filter(array_map(static fn (int $id): ?UnitNode => $snapshot->unit($id), $unitIds)));
        usort($units, static fn (UnitNode $a, UnitNode $b): int => $a->code <=> $b->code);

        foreach ($units as $unit) {
            $template = $this->template($unit->id, SubjectType::Unit, null, $referenceDate);
            [$approvers, $gaps] = $this->approvers($holders, $snapshot, $unit->id, null);

            $planned[] = new PlannedAssignment(
                subjectType: SubjectType::Unit,
                employeeId: null,
                unitId: $unit->id,
                sourceAssignmentId: null,
                subjectCode: $unit->code,
                subjectName: $unit->name,
                positionTitle: null,
                unitCode: $unit->code,
                unitName: $unit->name,
                unitType: $unit->type->value,
                unitPath: self::pathOf($snapshot, $unit->id),
                templateVersionId: $template?->id,
                templateCode: $template?->template->code,
                templateVersionNumber: $template?->version_number,
                coverage: $this->coverage($template, $referenceDate),
                approvers: $approvers,
                approverGaps: $gaps,
            );
        }

        return $planned;
    }

    private function template(int $unitId, SubjectType $subject, ?string $positionTitle, string $referenceDate): ?KpiTemplateVersion
    {
        $positionKey = $positionTitle === null ? '' : mb_strtolower(trim($positionTitle));

        foreach (array_unique([$positionKey, '']) as $key) {
            $cacheKey = "{$unitId}|{$subject->value}|{$key}";

            if (! array_key_exists($cacheKey, $this->templateCache)) {
                $this->templateCache[$cacheKey] = $this->templates
                    ->forScope($unitId, $subject->templateSubject(), $key === '' ? null : $key, $referenceDate)
                    ?->load('template');
            }

            if ($this->templateCache[$cacheKey] !== null) {
                return $this->templateCache[$cacheKey];
            }
        }

        return null;
    }

    /**
     * A template is only usable when every KPI version it pins is in force
     * on the reference date; otherwise scores would use a retired definition.
     */
    private function coverage(?KpiTemplateVersion $template, string $referenceDate): Coverage
    {
        if ($template === null) {
            return Coverage::NoTemplate;
        }

        $items = $template->items()->with('definitionVersion')->get();

        if ($items->isEmpty()) {
            return Coverage::NoTemplate;
        }

        $this->kpiInForceCache[$template->id] ??= $items
            ->every(static function (KpiTemplateItem $item) use ($referenceDate): bool {
                $definition = $item->definitionVersion;

                return in_array($definition->status, [LifecycleStatus::Published, LifecycleStatus::Retired], true)
                    && $definition->effective_from !== null
                    && $definition->effective_from <= $referenceDate
                    && ($definition->effective_until === null || $definition->effective_until > $referenceDate);
            });

        return $this->kpiInForceCache[$template->id] ? Coverage::Covered : Coverage::KpiNotInForce;
    }

    /**
     * @param  list<RoleHolder>  $holders
     * @return array{0: list<PlannedApprover>, 1: array<string, string>}
     */
    private function approvers(array $holders, OrganizationSnapshot $snapshot, int $unitId, ?int $subjectUserId): array
    {
        $approvers = [];
        $gaps = [];

        foreach (ApprovalStage::cases() as $stage) {
            $seen = [];
            $excludedSelf = false;

            foreach ($holders as $holder) {
                $scopeUnit = $snapshot->unit($holder->unitId);

                if ($holder->role->value !== $stage->roleKey() || $scopeUnit === null || ! $snapshot->contains($scopeUnit->id, $unitId)) {
                    continue;
                }

                if ($subjectUserId !== null && $holder->userId === $subjectUserId) {
                    $excludedSelf = true;

                    continue;
                }

                if (isset($seen[$holder->userId])) {
                    continue;
                }

                $seen[$holder->userId] = true;
                $approvers[] = new PlannedApprover(
                    stage: $stage,
                    role: $holder->role->value,
                    userId: $holder->userId,
                    userName: $holder->userName,
                    scopeUnitId: $scopeUnit->id,
                    scopeUnitCode: $scopeUnit->code,
                    roleAssignmentPublicId: $holder->assignmentPublicId,
                );
            }

            if ($seen === []) {
                $gaps[$stage->value] = $excludedSelf ? ApprovalStage::GAP_SELF_ONLY : ApprovalStage::GAP_NOT_CONFIGURED;
            }
        }

        return [$approvers, $gaps];
    }

    /**
     * @return list<array{id: int, code: string, name: string, type: string}>
     */
    private static function pathOf(OrganizationSnapshot $snapshot, int $unitId): array
    {
        return array_map(static fn (UnitNode $node): array => [
            'id' => $node->id,
            'code' => $node->code,
            'name' => $node->name,
            'type' => $node->type->value,
        ], $snapshot->path($unitId));
    }
}
