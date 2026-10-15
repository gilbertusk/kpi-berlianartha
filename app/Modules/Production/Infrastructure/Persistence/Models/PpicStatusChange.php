<?php

declare(strict_types=1);

namespace App\Modules\Production\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Production\Domain\PpicStatus;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of a work order on the PPIC board. Append-only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_unit_id
 * @property string $work_order
 * @property int $sequence
 * @property PpicStatus|null $from_status
 * @property PpicStatus $to_status
 * @property string|null $need_date
 * @property bool $was_overdue
 * @property string|null $reason
 * @property int $production_batch_id
 * @property int $changed_by_user_id
 * @property CarbonImmutable $changed_at
 * @property-read User $changedBy
 */
final class PpicStatusChange extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'from_status' => PpicStatus::class,
            'to_status' => PpicStatus::class,
            'need_date' => BusinessDate::class,
            'was_overdue' => 'boolean',
            'changed_at' => 'immutable_datetime',
        ];
    }
}
