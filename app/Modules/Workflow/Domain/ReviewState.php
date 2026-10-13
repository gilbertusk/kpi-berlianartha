<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

/**
 * Where one scorecard stands in the approval flow of a period in review
 * (ROLE_APPROVAL_MATRIX.md "Approval flow acuan", steps 4–6):
 * IN_REVIEW → REVIEWED → CALIBRATED → FINAL_APPROVED. Any stage may return
 * the scorecard; a resubmission or an approved correction starts a new round
 * at IN_REVIEW, and earlier rounds stay in the history.
 */
enum ReviewState: string
{
    case InReview = 'in_review';
    case Reviewed = 'reviewed';
    case Calibrated = 'calibrated';
    case FinalApproved = 'final_approved';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::InReview => 'Menunggu review',
            self::Reviewed => 'Menunggu kalibrasi',
            self::Calibrated => 'Menunggu approval final',
            self::FinalApproved => 'Final disetujui',
            self::Returned => 'Dikembalikan',
        };
    }

    /**
     * The approval stage (PeriodAssignment ApprovalStage value) that acts
     * next, or null when nothing is pending.
     */
    public function pendingStage(): ?string
    {
        return match ($this) {
            self::InReview => 'review',
            self::Reviewed => 'calibration',
            self::Calibrated => 'final_approval',
            default => null,
        };
    }
}
