<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Shared\Application\Outbox\OutboxConsumer;
use App\Modules\Shared\Application\Outbox\OutboxEnvelope;
use RuntimeException;

final class RecordingOutboxConsumer implements OutboxConsumer
{
    public int $handled = 0;

    /** @var list<string> */
    public array $messageIds = [];

    public function __construct(
        private readonly string $consumerName = 'recording-test-consumer',
        private readonly bool $shouldFail = false,
    ) {}

    public function name(): string
    {
        return $this->consumerName;
    }

    public function supports(string $eventType, int $eventVersion): bool
    {
        return $eventType === 'TestEvent' && $eventVersion === 1;
    }

    public function handle(OutboxEnvelope $message): void
    {
        $this->handled++;
        $this->messageIds[] = $message->messageId;

        if ($this->shouldFail) {
            throw new RuntimeException('synthetic consumer failure');
        }
    }
}
