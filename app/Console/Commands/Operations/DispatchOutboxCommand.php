<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Jobs\ProcessOutboxMessage;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use Illuminate\Console\Command;

final class DispatchOutboxCommand extends Command
{
    protected $signature = 'ops:outbox-dispatch {--limit=100 : Maximum messages to enqueue}';

    protected $description = 'Enqueue due transactional outbox messages without exposing their payloads';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->components->error('Limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        $messageIds = OutboxMessage::query()
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->where('available_at', '<=', now('UTC'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('public_id');

        foreach ($messageIds as $messageId) {
            ProcessOutboxMessage::dispatch((string) $messageId)->afterCommit();
        }

        $this->components->info("Enqueued {$messageIds->count()} outbox message(s).");

        return self::SUCCESS;
    }
}
