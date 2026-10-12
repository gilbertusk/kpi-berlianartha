<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Domain\AssignmentType;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $employee_id
 * @property int $organization_unit_id
 * @property AssignmentType $assignment_type
 * @property string|null $position_title
 * @property string $effective_from
 * @property string|null $effective_until
 * @property string|null $reason
 * @property int|null $created_by_user_id
 * @property string|null $end_reason
 * @property int|null $ended_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Employee $employee
 * @property-read OrganizationUnit $unit
 * @property-read User|null $createdBy
 * @property-read User|null $endedBy
 */
final class EmployeeAssignment extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * New rows start at version 1 so optimistic locking works on the
     * returned model without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
    ];

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignment_type' => AssignmentType::class,
            'effective_from' => BusinessDate::class,
            'effective_until' => BusinessDate::class,
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
