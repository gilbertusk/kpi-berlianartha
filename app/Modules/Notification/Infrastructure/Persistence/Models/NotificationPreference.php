<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Persistence\Models;

use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\NotificationCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property NotificationCategory $category
 * @property Channel $channel
 * @property bool $is_enabled
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class NotificationPreference extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'channel' => Channel::class,
            'is_enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
