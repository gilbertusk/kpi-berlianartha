<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Outbox;

use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxDelivery;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class OutboxProcessor
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private OutboxConsumerRegistry $registry) {}

    /**
     * @throws Throwable
     */
    public function process(string $messageId): void
    {
        try {
            DB::transaction(function () use ($messageId): void {
                $message = OutboxMessage::query()
                    ->where('public_id', $messageId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($message->processed_at !== null || $message->failed_at !== null) {
                    return;
                }

                $envelope = $message->toEnvelope();

                foreach ($this->registry->consumersFor($message->event_type, $message->event_version) as $consumer) {
                    $delivery = OutboxDelivery::query()->firstOrNew([
                        'outbox_message_id' => $message->getKey(),
                        'consumer_name' => $consumer->name(),
                    ]);

                    if ($delivery->processed_at !== null) {
                        continue;
                    }

                    $consumer->handle($envelope);

                    $delivery->forceFill([
                        'processed_at' => now('UTC'),
                        'created_at' => $delivery->created_at ?? now('UTC'),
                    ])->save();
                }

                $message->forceFill([
                    'attempts' => $message->attempts + 1,
                    'processed_at' => now('UTC'),
                    'last_error_code' => null,
                ])->save();
            }, 3);
        } catch (Throwable $exception) {
            $this->recordFailure($messageId);

            throw $exception;
        }
    }

    private function recordFailure(string $messageId): void
    {
        $message = OutboxMessage::query()
            ->where('public_id', $messageId)
            ->whereNull('processed_at')
            ->first();

        if ($message === null) {
            return;
        }

        $attempts = $message->attempts + 1;
        $failed = $attempts >= self::MAX_ATTEMPTS;

        $message->forceFill([
            'attempts' => $attempts,
            'last_error_code' => 'consumer_failure',
            'available_at' => $failed ? $message->available_at : now('UTC')->addSeconds(min(300, 5 * (2 ** $attempts))),
            'failed_at' => $failed ? now('UTC') : null,
        ])->save();
    }
}
