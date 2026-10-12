<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence\Models;

use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property array<string, mixed>|null $actor_scope
 * @property array<string, mixed>|null $before_values
 * @property array<string, mixed>|null $after_values
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
final class AuditLog extends Model
{
    use HasPublicUlid;

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Audit records are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Audit records are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_scope' => 'array',
            'before_values' => 'array',
            'after_values' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
