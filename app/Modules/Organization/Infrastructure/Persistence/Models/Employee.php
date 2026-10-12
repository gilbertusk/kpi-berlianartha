<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee master record (CONFIDENTIAL, DATA_CLASSIFICATION.md). Team
 * membership is never stored here; it lives in effective-dated assignments.
 *
 * @property int $id
 * @property string $public_id
 * @property string $employee_number
 * @property string $full_name
 * @property int|null $user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $user
 */
final class Employee extends Model
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

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EmployeeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
