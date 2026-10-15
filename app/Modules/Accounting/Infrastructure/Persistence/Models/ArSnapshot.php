<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the accounting system reported on one as-of date, as one file.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_unit_id
 * @property string $source_system
 * @property string $as_of_date
 * @property int $version
 * @property string $content_hash
 * @property string $original_name
 * @property string $status staged|committed|rejected
 * @property int $row_count
 * @property int $error_count
 * @property list<string>|null $errors
 * @property string|null $control_invoice_total
 * @property string|null $control_allocation_total
 * @property int $staged_by_user_id
 * @property int|null $committed_by_user_id
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $unit
 * @property-read User $stagedBy
 * @property-read User|null $committedBy
 */
final class ArSnapshot extends Model
{
    use HasPublicUlid;

    public const STAGED = 'staged';

    public const COMMITTED = 'committed';

    public const REJECTED = 'rejected';

    protected $guarded = [];

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
    public function stagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staged_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by_user_id');
    }

    /**
     * @return HasMany<ArDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ArDocument::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of_date' => BusinessDate::class,
            'errors' => 'array',
            'version' => 'integer',
            'row_count' => 'integer',
            'error_count' => 'integer',
            'control_invoice_total' => 'decimal:2',
            'control_allocation_total' => 'decimal:2',
            'committed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
