<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $scope
 * @property string $key_hash
 * @property string $request_fingerprint
 * @property string $status
 * @property array<string, mixed>|null $response_payload
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $completed_at
 */
final class IdempotencyKey extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_payload' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
