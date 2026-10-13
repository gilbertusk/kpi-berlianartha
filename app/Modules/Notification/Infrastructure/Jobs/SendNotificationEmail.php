<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Jobs;

use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Infrastructure\Mail\NotificationMail;
use App\Modules\Notification\Infrastructure\Persistence\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the email copy of one notification. Safe to run more than once: a
 * delivery that was already sent is left alone. Each attempt is counted and
 * only the exception class is stored as the error code.
 */
final class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $deliveryId) {}

    /**
     * A redelivered copy of the job never runs while another one is still
     * sending the same delivery; the lock expires if a worker dies.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("notification-email:{$this->deliveryId}"))->expireAfter(300)->dontRelease()];
    }

    public function handle(): void
    {
        $delivery = DB::transaction(function (): ?NotificationDelivery {
            $delivery = NotificationDelivery::query()->lockForUpdate()->find($this->deliveryId);

            if ($delivery === null || $delivery->status !== DeliveryStatus::Pending) {
                return null;
            }

            $delivery->forceFill(['attempts' => $delivery->attempts + 1, 'last_attempt_at' => CarbonImmutable::now()])->save();

            return $delivery;
        });

        if ($delivery === null) {
            return;
        }

        $notification = $delivery->notification()->with('user')->firstOrFail();

        if (! $notification->user->isActive()) {
            $delivery->forceFill(['status' => DeliveryStatus::Skipped, 'last_error_code' => 'recipient_inactive'])->save();

            return;
        }

        try {
            Mail::to($notification->user->email)->send(new NotificationMail($notification));
        } catch (Throwable $exception) {
            $delivery->forceFill(['last_error_code' => mb_substr(class_basename($exception), 0, 60)])->save();

            throw $exception;
        }

        $delivery->forceFill(['status' => DeliveryStatus::Sent, 'delivered_at' => CarbonImmutable::now(), 'last_error_code' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        NotificationDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', DeliveryStatus::Pending->value)
            ->update(['status' => DeliveryStatus::Failed->value]);
    }
}
