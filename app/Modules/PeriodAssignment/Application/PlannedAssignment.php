<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\SubjectType;

/**
 * What the snapshot of one subject will contain. The same plan feeds the
 * preview of a draft period and the rows stored when the period is opened,
 * so HR opens exactly what was previewed.
 */
final readonly class PlannedAssignment
{
    /**
     * @param  list<array{id: int, code: string, name: string, type: string}>  $unitPath
     * @param  list<PlannedApprover>  $approvers
     * @param  array<string, string>  $approverGaps  stage value => gap reason
     */
    public function __construct(
        public SubjectType $subjectType,
        public ?int $employeeId,
        public int $unitId,
        public ?int $sourceAssignmentId,
        public string $subjectCode,
        public string $subjectName,
        public ?string $positionTitle,
        public string $unitCode,
        public string $unitName,
        public string $unitType,
        public array $unitPath,
        public ?int $templateVersionId,
        public ?string $templateCode,
        public ?int $templateVersionNumber,
        public Coverage $coverage,
        public array $approvers,
        public array $approverGaps,
    ) {}

    public function subjectKey(): string
    {
        return $this->subjectType->key($this->subjectType === SubjectType::Employee ? (int) $this->employeeId : $this->unitId);
    }

    public function pathLabel(): string
    {
        $names = [];

        foreach ($this->unitPath as $node) {
            if ($node['type'] !== 'company') {
                $names[] = $node['name'];
            }
        }

        return $names === [] ? $this->unitName : implode(' › ', $names);
    }

    /**
     * @return list<PlannedApprover>
     */
    public function approversAt(ApprovalStage $stage): array
    {
        return array_values(array_filter($this->approvers, static fn (PlannedApprover $approver): bool => $approver->stage === $stage));
    }
}
