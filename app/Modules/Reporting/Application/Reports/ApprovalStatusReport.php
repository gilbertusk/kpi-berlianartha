<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignmentApprover;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use Carbon\CarbonImmutable;

/**
 * Where each scorecard stands in approval: state, the stage that waits, who
 * was configured for it, any approver gap, and how long it has waited. No
 * approval SLA is approved (DEC-009), so the report gives the age in days
 * and does not label anything "overdue".
 */
final class ApprovalStatusReport extends ScorecardBasedReport
{
    public function key(): string
    {
        return 'approvals';
    }

    public function title(): string
    {
        return 'Status approval dan yang menunggu';
    }

    public function description(): string
    {
        return 'Status approval per scorecard, tahap yang menunggu, approver, kekosongan approver, dan umur tunggu dalam hari.';
    }

    public function columns(): array
    {
        return ['Periode', 'Jenis subjek', 'Kode subjek', 'Nama subjek', 'Unit', 'Status approval', 'Tahap menunggu', 'Approver tahap menunggu', 'Kekosongan approver', 'Putaran', 'Menunggu sejak (UTC)', 'Umur tunggu (hari)'];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'level', 'employee', 'status'];
    }

    public function statuses(): array
    {
        $statuses = [];

        foreach (ReviewState::cases() as $state) {
            $statuses[$state->value] = $state->label();
        }

        return $statuses;
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function rows(ReportContext $context): iterable
    {
        $now = CarbonImmutable::now();

        $reviews = ScorecardReview::query()
            ->with(['assignment.approvers'])
            ->whereIn('period_assignment_id', $this->assignments($context)->select('period_assignments.id'))
            ->when($context->filters->status !== null, fn ($query) => $query->where('state', $context->filters->status))
            ->orderBy('id')
            ->lazyById(self::CHUNK);

        foreach ($reviews as $review) {
            $assignment = $review->assignment;
            $stage = $review->pendingStage();
            $approvers = $stage === null ? '' : $assignment->approvers
                ->filter(static fn (PeriodAssignmentApprover $approver): bool => $approver->stage === $stage)
                ->map(static fn (PeriodAssignmentApprover $approver): string => $approver->user_name)
                ->implode(', ');
            $gap = $stage === null ? null : (($assignment->approver_gaps ?? [])[$stage->value] ?? null);

            yield [
                $context->period?->code, $assignment->subject_type->value, $assignment->subject_code, $assignment->subject_name, $assignment->unit_code,
                $review->state->label(), $stage?->label(), $approvers, $gap === null ? null : ApprovalStage::gapLabel($gap), (string) $review->round,
                $stage === null ? null : $review->updated_at->format('Y-m-d H:i:s'),
                $stage === null ? null : (string) (int) $review->updated_at->diffInDays($now),
            ];
        }
    }
}
