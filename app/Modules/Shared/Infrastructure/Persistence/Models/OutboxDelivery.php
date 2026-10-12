<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $outbox_message_id
 * @property string $consumer_name
 * @property \Carbon\CarbonImmutable|null $processed_at
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class OutboxDelivery extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
