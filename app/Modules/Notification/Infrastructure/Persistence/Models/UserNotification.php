<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing one account was told. The deduplication key makes repeated
 * processing of the same event or reminder a no-op. The link is a route
 * name and parameters; the destination page authorizes the reader itself.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property NotificationCategory $category
 * @property string $title
 * @property string $body
 * @property string|null $route_name
 * @property array<string, string>|null $route_parameters
 * @property string $deduplication_key
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Collection<int, NotificationDelivery> $deliveries
 */
final class UserNotification extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'route_parameters' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
