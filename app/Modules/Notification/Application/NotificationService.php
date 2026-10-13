<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Infrastructure\Jobs\SendNotificationEmail;
use App\Modules\Notification\Infrastructure\Persistence\Models\NotificationDelivery;
use App\Modules\Notification\Infrastructure\Persistence\Models\NotificationPreference;
use App\Modules\Notification\Infrastructure\Persistence\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates notifications exactly once per recipient and deduplication key,
 * records the in-app delivery, and queues the email copy when the email
 * channel is approved and the recipient has not switched it off.
 */
final readonly class NotificationService
{
    public function __construct(private bool $emailEnabled) {}

    public function emailEnabled(): bool
    {
        return $this->emailEnabled;
    }

    /**
     * @param  iterable<int>  $userIds
     * @return int number of notifications created (0 when all existed already)
     */
    public function notify(iterable $userIds, NotificationMessage $message): int
    {
        $created = 0;
        $ids = array_values(array_unique([...$userIds]));

        if ($ids === []) {
            return 0;
        }

        // A deactivated account is told nothing.
        $active = User::query()->whereIn('id', $ids)->where('status', AccountStatus::Active->value)->orderBy('id')->pluck('id');

        foreach ($active as $userId) {
            $created += $this->notifyOne((int) $userId, $message) ? 1 : 0;
        }

        return $created;
    }

    public function markRead(UserNotification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => CarbonImmutable::now()])->save();
        }
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => CarbonImmutable::now()]);
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count();
    }

    /**
     * Email preference per category; a category without a stored choice is on.
     *
     * @return array<string, bool> category value => enabled
     */
    public function emailPreferences(User $user): array
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('channel', Channel::Email->value)
            ->get()
            ->mapWithKeys(static fn (NotificationPreference $preference): array => [$preference->category->value => $preference->is_enabled])
            ->all();

        $preferences = [];

        foreach (NotificationCategory::cases() as $category) {
            $preferences[$category->value] = $stored[$category->value] ?? true;
        }

        return $preferences;
    }

    public function setEmailPreference(User $user, NotificationCategory $category, bool $enabled): void
    {
        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'category' => $category->value, 'channel' => Channel::Email->value],
            ['is_enabled' => $enabled],
        );
    }

    /**
     * Queues again the email deliveries that are still pending after the
     * given moment, for example because the queue was down when they were
     * first dispatched. The job is idempotent, so a duplicate is harmless.
     */
    public function requeuePendingEmails(CarbonImmutable $olderThan, int $limit = 200): int
    {
        if (! $this->emailEnabled) {
            return 0;
        }

        $ids = NotificationDelivery::query()
            ->where('channel', Channel::Email->value)
            ->where('status', DeliveryStatus::Pending->value)
            ->where('created_at', '<', $olderThan)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            SendNotificationEmail::dispatch((int) $id);
        }

        return $ids->count();
    }

    private function notifyOne(int $userId, NotificationMessage $message): bool
    {
        return DB::transaction(function () use ($userId, $message): bool {
            // The unique index decides: a concurrent or repeated run inserts nothing.
            $inserted = UserNotification::query()->insertOrIgnore([
                'public_id' => (string) str()->ulid(),
                'user_id' => $userId,
                'category' => $message->category->value,
                'title' => mb_substr($message->title, 0, 200),
                'body' => $message->body,
                'route_name' => $message->routeName,
                'route_parameters' => $message->routeParameters === [] ? null : json_encode($message->routeParameters, JSON_THROW_ON_ERROR),
                'deduplication_key' => $message->deduplicationKey,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            $notification = UserNotification::query()->where('user_id', $userId)->where('deduplication_key', $message->deduplicationKey)->firstOrFail();

            NotificationDelivery::query()->create([
                'user_notification_id' => $notification->id,
                'channel' => Channel::InApp,
                'status' => DeliveryStatus::Sent,
                'attempts' => 1,
                'last_attempt_at' => CarbonImmutable::now(),
                'delivered_at' => CarbonImmutable::now(),
            ]);

            if ($this->emailEnabled) {
                $this->queueEmail($notification, $message->category);
            }

            return true;
        });
    }

    private function queueEmail(UserNotification $notification, NotificationCategory $category): void
    {
        $wanted = NotificationPreference::query()
            ->where('user_id', $notification->user_id)
            ->where('category', $category->value)
            ->where('channel', Channel::Email->value)
            ->value('is_enabled');

        $delivery = NotificationDelivery::query()->create([
            'user_notification_id' => $notification->id,
            'channel' => Channel::Email,
            'status' => $wanted === null || (bool) $wanted ? DeliveryStatus::Pending : DeliveryStatus::Skipped,
        ]);

        if ($delivery->status === DeliveryStatus::Pending) {
            SendNotificationEmail::dispatch($delivery->id)->afterCommit();
        }
    }
}
