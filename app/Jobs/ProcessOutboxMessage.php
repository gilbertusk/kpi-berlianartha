<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Shared\Application\Outbox\OutboxProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessOutboxMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue('outbox');
    }

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 180, 300];
    }

    public function handle(OutboxProcessor $processor): void
    {
        $processor->process($this->messageId);
    }
}
