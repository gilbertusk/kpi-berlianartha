<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\KpiGovernance\Application\Policies\KpiDefinitionPolicy;
use App\Modules\KpiGovernance\Application\Policies\KpiTemplatePolicy;
use App\Modules\KpiGovernance\Domain\Scoring\ScoreStrategyRegistry;
use App\Modules\KpiGovernance\Infrastructure\Console\ImportKpiCandidatesCommand;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class KpiGovernanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScoreStrategyRegistry::class);
    }

    public function boot(): void
    {
        Gate::policy(KpiDefinition::class, KpiDefinitionPolicy::class);
        Gate::policy(KpiTemplate::class, KpiTemplatePolicy::class);

        Gate::define('kpi.view', fn (User $user): bool => $this->app->make(AccessControl::class)->allowsAny($user, [
            Capability::KpiDefinitionView,
            Capability::KpiTemplateView,
        ]));

        if ($this->app->runningInConsole()) {
            $this->commands([ImportKpiCandidatesCommand::class]);
        }
    }
}
