<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Persistence\Models;

use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\DeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery of one notification through one channel: how often it was
 * tried and how it ended. Only an error code is kept, never message text.
 *
 * @property int $id
 * @property int $user_notification_id
 * @property Channel $channel
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property string|null $last_error_code
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read UserNotification $notification
 */
final class NotificationDelivery extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<UserNotification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class, 'user_notification_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
