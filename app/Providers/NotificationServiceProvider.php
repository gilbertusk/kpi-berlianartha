<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Application\WorkflowNotificationConsumer;
use App\Modules\Notification\Infrastructure\Console\SendRemindersCommand;
use Illuminate\Support\ServiceProvider;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(NotificationService::class)->needs('$emailEnabled')->giveConfig('notifications.email.enabled');
        $this->app->tag([WorkflowNotificationConsumer::class], 'outbox.consumers');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SendRemindersCommand::class]);
        }
    }
}
