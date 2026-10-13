<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Modules\IdentityAccess\Application\Grant;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;

/**
 * Why an account may decide one stage of one scorecard: the grant it acts
 * under and, for a delegation, the approver it acts for.
 */
final readonly class StageAuthority
{
    public function __construct(
        public ApprovalStage $stage,
        public Grant $grant,
        public ?int $actingForUserId,
    ) {}

    /**
     * @return array<string, string|int|null>
     */
    public function toAudit(): array
    {
        return [
            'stage' => $this->stage->value,
            'role' => $this->grant->role->value,
            'grant_source' => $this->grant->source,
            'grant_reference' => $this->grant->sourcePublicId,
            'acting_for_user_id' => $this->actingForUserId,
        ];
    }
}
