<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use Illuminate\Console\Command;

/**
 * Manual recovery of a dead-lettered outbox message: after its bounded
 * retries it waits for an operator, who requeues it once the consumer's
 * cause is fixed. The payload is never printed.
 */
final class RequeueOutboxCommand extends Command
{
    protected $signature = 'ops:outbox-requeue {message : Public id of a dead-lettered outbox message} {--reason= : Why it is requeued}';

    protected $description = 'Requeue one dead-lettered transactional outbox message';

    public function handle(TransactionBoundary $transactions, AuditRecorder $audit): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->components->error('Alasan (--reason) wajib diisi.');

            return self::INVALID;
        }

        $requeued = $transactions->run(function () use ($audit, $reason): bool {
            $message = OutboxMessage::query()->where('public_id', (string) $this->argument('message'))->whereNull('processed_at')->whereNotNull('failed_at')->lockForUpdate()->first();

            if ($message === null) {
                return false;
            }

            $attempts = $message->attempts;
            $lastError = $message->getAttribute('last_error_code');
            $message->forceFill(['failed_at' => null, 'attempts' => 0, 'available_at' => now('UTC'), 'last_error_code' => null])->save();
            $audit->record(new AuditEntry(
                action: 'outbox.requeued',
                entityType: 'outbox_message',
                entityId: $message->public_id,
                before: ['attempts' => $attempts, 'dead_letter' => true, 'last_error_code' => $lastError],
                after: ['attempts' => 0, 'dead_letter' => false],
                reason: mb_substr($reason, 0, 1000),
                context: Actor::system('operator-cli')->audit,
            ));

            return true;
        });

        if (! $requeued) {
            $this->components->error('Pesan dead-letter tidak ditemukan.');

            return self::FAILURE;
        }

        $this->components->info('Pesan dimasukkan kembali ke antrean.');

        return self::SUCCESS;
    }
}
