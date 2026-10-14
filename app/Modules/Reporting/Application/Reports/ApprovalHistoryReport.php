<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Workflow\Domain\DecisionOutcome;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReviewDecision;

/**
 * The append-only approval history: every decision with its stage, round,
 * who decided under which role, and for whom when delegated.
 */
final class ApprovalHistoryReport extends ScorecardBasedReport
{
    public function key(): string
    {
        return 'approval_history';
    }

    public function title(): string
    {
        return 'Riwayat keputusan approval';
    }

    public function description(): string
    {
        return 'Setiap keputusan review, kalibrasi, approval final, pengembalian, bypass, dan koreksi beserta pelaku dan role-nya.';
    }

    public function columns(): array
    {
        return ['Periode', 'Diputuskan pada (UTC)', 'Kode subjek', 'Nama subjek', 'Unit', 'Putaran', 'Tahap', 'Keputusan', 'Oleh', 'Role', 'Atas nama (delegasi)', 'Sumber grant', 'Catatan'];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'level', 'employee'];
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function rows(ReportContext $context): iterable
    {
        $decisions = ScorecardReviewDecision::query()
            ->with(['actor', 'actingFor'])
            ->whereIn('scorecard_review_id', ScorecardReview::query()
                ->whereIn('period_assignment_id', $this->assignments($context)->select('period_assignments.id'))
                ->select('id'))
            ->orderBy('id')
            ->lazyById(self::CHUNK);

        $subjects = [];

        foreach ($decisions as $decision) {
            $subjects[$decision->scorecard_review_id] ??= ScorecardReview::query()->with('assignment')->findOrFail($decision->scorecard_review_id)->assignment;
            $assignment = $subjects[$decision->scorecard_review_id];

            yield [
                $context->period?->code, $decision->decided_at->format('Y-m-d H:i:s'), $assignment->subject_code, $assignment->subject_name, $assignment->unit_code,
                (string) $decision->round, DecisionOutcome::stageLabel($decision->stage), $decision->outcome->label(), $decision->actor->name,
                $decision->actor_role, $decision->actingFor?->name, $decision->grant_source, $decision->comment,
            ];
        }
    }
}
