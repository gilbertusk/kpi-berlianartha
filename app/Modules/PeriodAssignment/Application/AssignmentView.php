<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;

/**
 * One row of "who is assessed by whom", read either from the plan of a draft
 * period (preview) or from the stored snapshot of an opened period.
 */
final readonly class AssignmentView
{
    /**
     * @param  array<string, list<string>>  $approvers  stage value => approver names
     * @param  array<string, string>  $gaps  stage value => gap reason
     */
    public function __construct(
        public string $key,
        public SubjectType $subjectType,
        public string $subjectCode,
        public string $subjectName,
        public ?string $positionTitle,
        public int $unitId,
        public string $unitLabel,
        public ?string $templateLabel,
        public Coverage $coverage,
        public array $approvers,
        public array $gaps,
    ) {}

    public static function fromPlan(PlannedAssignment $planned): self
    {
        $approvers = [];

        foreach ($planned->approvers as $approver) {
            $approvers[$approver->stage->value][] = "{$approver->userName} ({$approver->scopeUnitCode})";
        }

        return new self(
            key: $planned->subjectKey(),
            subjectType: $planned->subjectType,
            subjectCode: $planned->subjectCode,
            subjectName: $planned->subjectName,
            positionTitle: $planned->positionTitle,
            unitId: $planned->unitId,
            unitLabel: $planned->pathLabel(),
            templateLabel: $planned->templateCode === null ? null : "{$planned->templateCode} v{$planned->templateVersionNumber}",
            coverage: $planned->coverage,
            approvers: $approvers,
            gaps: $planned->approverGaps,
        );
    }

    public static function fromSnapshot(PeriodAssignment $assignment): self
    {
        $approvers = [];

        foreach ($assignment->approvers as $approver) {
            $approvers[$approver->stage->value][] = "{$approver->user_name} ({$approver->scope_unit_code})";
        }

        return new self(
            key: $assignment->subject_key,
            subjectType: $assignment->subject_type,
            subjectCode: $assignment->subject_code,
            subjectName: $assignment->subject_name,
            positionTitle: $assignment->position_title,
            unitId: $assignment->organization_unit_id,
            unitLabel: $assignment->pathLabel(),
            templateLabel: $assignment->template_code === null ? null : "{$assignment->template_code} v{$assignment->template_version_number}",
            coverage: $assignment->coverage,
            approvers: $approvers,
            gaps: $assignment->approver_gaps ?? [],
        );
    }

    public function approverText(ApprovalStage $stage): string
    {
        if (isset($this->approvers[$stage->value])) {
            return implode(', ', $this->approvers[$stage->value]);
        }

        return ApprovalStage::gapLabel($this->gaps[$stage->value] ?? ApprovalStage::GAP_NOT_CONFIGURED);
    }

    public function matches(?string $subjectType, ?string $coverage, bool $gapsOnly, string $needle): bool
    {
        if ($subjectType !== null && $this->subjectType->value !== $subjectType) {
            return false;
        }

        if ($coverage !== null && ($this->coverage === Coverage::Covered) !== ($coverage === Coverage::Covered->value)) {
            return false;
        }

        if ($gapsOnly && $this->gaps === []) {
            return false;
        }

        return $needle === ''
            || str_contains(mb_strtolower($this->subjectCode), $needle)
            || str_contains(mb_strtolower($this->subjectName), $needle);
    }
}
