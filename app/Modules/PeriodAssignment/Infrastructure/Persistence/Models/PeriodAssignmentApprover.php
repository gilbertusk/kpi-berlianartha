<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Infrastructure\Persistence\Models;

use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One account in the approver chain of a period assignment, as configured
 * when the period was opened. Immutable (database trigger).
 *
 * @property int $id
 * @property int $period_assignment_id
 * @property ApprovalStage $stage
 * @property string $role
 * @property int $user_id
 * @property string $user_name
 * @property int $scope_unit_id
 * @property string $scope_unit_code
 * @property string $role_assignment_public_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PeriodAssignmentApprover extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => ApprovalStage::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
