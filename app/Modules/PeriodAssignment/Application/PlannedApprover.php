<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\PeriodAssignment\Domain\ApprovalStage;

/**
 * One account that will act at an approval stage for a planned assignment.
 */
final readonly class PlannedApprover
{
    public function __construct(
        public ApprovalStage $stage,
        public string $role,
        public int $userId,
        public string $userName,
        public int $scopeUnitId,
        public string $scopeUnitCode,
        public string $roleAssignmentPublicId,
    ) {}
}
