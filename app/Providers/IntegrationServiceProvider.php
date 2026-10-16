<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Integration\Application\Adapters\ProductionBatchAdapter;
use App\Modules\Integration\Application\Adapters\ReceivablesAdapter;
use App\Modules\Integration\Infrastructure\Console\MigrateEmployeesCommand;
use App\Modules\Integration\Infrastructure\Console\RetryIntegrationRunCommand;
use App\Modules\Integration\Infrastructure\Console\RunIntegrationCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('integration.adapter.production', ProductionBatchAdapter::class);
        $this->app->bind('integration.adapter.receivables', ReceivablesAdapter::class);
    }

    public function boot(): void
    {
        Gate::define('integrations.view', fn (User $user): bool => $this->app->make(AccessControl::class)->allows($user, Capability::IntegrationView));

        if ($this->app->runningInConsole()) {
            $this->commands([RunIntegrationCommand::class, RetryIntegrationRunCommand::class, MigrateEmployeesCommand::class]);
        }
    }
}
