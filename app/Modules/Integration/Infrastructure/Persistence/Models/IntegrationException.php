<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry of the exception register: a quarantined file, rejected rows, a
 * reconciliation difference, a migration conflict, or a dead letter, with
 * its data owner and decision.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $integration_run_id
 * @property string $connection
 * @property string $kind
 * @property string $detail
 * @property string $data_owner
 * @property string $status open|accepted|resolved
 * @property string|null $resolution_reason
 * @property int|null $resolved_by_user_id
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 * @property-read IntegrationRun|null $run
 * @property-read User|null $resolvedBy
 */
final class IntegrationException extends Model
{
    use HasPublicUlid;

    public const OPEN = 'open';

    public const ACCEPTED = 'accepted';

    public const RESOLVED = 'resolved';

    protected $guarded = [];

    /**
     * @return BelongsTo<IntegrationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(IntegrationRun::class, 'integration_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
