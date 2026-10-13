<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;

/**
 * One scorecard review as shown in the inbox or on the period board.
 */
final readonly class ReviewRow
{
    /**
     * @param  array{score: string|null, rating_key: string|null, rating_label: string|null, adjusted: bool}  $score
     * @param  ApprovalStage|null  $actionable  the stage this account may decide now
     * @param  bool  $viaBypass  calibration is possible because a signed bypass covers the missing review
     * @param  string|null  $waitingOn  why nobody can act (an approver gap), if so
     */
    public function __construct(
        public ScorecardReview $review,
        public array $score,
        public ?ApprovalStage $actionable,
        public bool $viaBypass,
        public ?string $waitingOn,
        public bool $stale,
    ) {}
}
