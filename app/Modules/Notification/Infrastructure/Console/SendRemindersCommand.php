<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Console;

use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Application\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class SendRemindersCommand extends Command
{
    protected $signature = 'notifications:send-reminders';

    protected $description = 'Send the reminders and escalations of the approved rules for today (idempotent).';

    public function handle(ReminderService $reminders, NotificationService $notifications): int
    {
        $requeued = $notifications->requeuePendingEmails(CarbonImmutable::now()->subMinutes(15));

        if ($requeued > 0) {
            $this->info("Pending email deliveries queued again: {$requeued}.");
        }

        try {
            $result = $reminders->run(CarbonImmutable::now());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['rules'] === 0) {
            $this->info('No approved reminder rules (DEC-009 open): nothing sent.');

            return self::SUCCESS;
        }

        $this->info("Rules: {$result['rules']}; notifications created: {$result['sent']}.");

        return self::SUCCESS;
    }
}
