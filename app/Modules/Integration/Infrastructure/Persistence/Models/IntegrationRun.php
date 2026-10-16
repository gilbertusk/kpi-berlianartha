<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Persistence\Models;

use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One inbound file or migration run.
 *
 * @property int $id
 * @property string $public_id
 * @property string $connection
 * @property string $mode inbound|migration_dry_run|migration_apply
 * @property string $file_name
 * @property string|null $content_hash
 * @property array<string, mixed>|null $manifest
 * @property string $status
 * @property int $attempts
 * @property int|null $row_count
 * @property int|null $accepted_count
 * @property int|null $rejected_count
 * @property int|null $duplicate_count
 * @property array<string, mixed>|null $reconciliation
 * @property bool|null $reconciled
 * @property CarbonImmutable|null $watermark
 * @property string|null $target_type
 * @property string|null $target_public_id
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class IntegrationRun extends Model
{
    use HasPublicUlid;

    public const RECEIVED = 'received';

    public const QUARANTINED = 'quarantined';

    public const STAGED = 'staged';

    public const COMMITTED = 'committed';

    public const DUPLICATE = 'duplicate';

    public const FAILED = 'failed';

    public const DEAD_LETTER = 'dead_letter';

    public const DRY_RUN = 'dry_run';

    public const APPLIED = 'applied';

    public const INBOUND = 'inbound';

    public const MIGRATION_DRY_RUN = 'migration_dry_run';

    public const MIGRATION_APPLY = 'migration_apply';

    protected $guarded = [];

    /**
     * @return HasMany<IntegrationException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(IntegrationException::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'attempts' => 'integer',
            'row_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'duplicate_count' => 'integer',
            'reconciliation' => 'array',
            'reconciled' => 'boolean',
            'watermark' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
