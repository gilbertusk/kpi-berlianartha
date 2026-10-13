<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\ActualEvidence\Domain\ScanStatus;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Persistence\Models\StoredObjectMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata of one evidence file of an actual. The binary is in private
 * object storage under a generated key; `original_name` is display text.
 *
 * @property int $id
 * @property string $public_id
 * @property int $kpi_actual_id
 * @property int $stored_object_id
 * @property string $original_name
 * @property string $detected_mime
 * @property int $byte_size
 * @property string $checksum
 * @property ScanStatus $scan_status
 * @property string|null $scanner
 * @property CarbonImmutable|null $scanned_at
 * @property int|null $uploaded_by_user_id
 * @property int|null $removed_by_user_id
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read KpiActual $actual
 * @property-read StoredObjectMetadata $storedObject
 * @property-read User|null $uploadedBy
 */
final class EvidenceFile extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<KpiActual, $this>
     */
    public function actual(): BelongsTo
    {
        return $this->belongsTo(KpiActual::class, 'kpi_actual_id');
    }

    /**
     * @return BelongsTo<StoredObjectMetadata, $this>
     */
    public function storedObject(): BelongsTo
    {
        return $this->belongsTo(StoredObjectMetadata::class, 'stored_object_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scan_status' => ScanStatus::class,
            'byte_size' => 'integer',
            'scanned_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
