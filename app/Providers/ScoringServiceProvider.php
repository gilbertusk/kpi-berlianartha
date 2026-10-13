<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Scoring\Application\ScorecardPolicyCatalog;
use App\Modules\Scoring\Application\ScorePeriodAccess;
use App\Modules\Scoring\Domain\Strategy\CalculatorRegistry;
use App\Modules\Scoring\Infrastructure\ConfiguredScorecardPolicies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class ScoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CalculatorRegistry::class);
        $this->app->singleton(ScorecardPolicyCatalog::class, ConfiguredScorecardPolicies::class);
    }

    public function boot(): void
    {
        Gate::define('scorecards.view', fn (User $user): bool => $this->app->make(AccessControl::class)->allows($user, Capability::ScorecardView));

        Gate::define('viewScorecards', fn (User $user, AssessmentPeriod $period): bool => $this->app->make(ScorePeriodAccess::class)->canView($user, $period));
        Gate::define('calculateScorecards', fn (User $user, AssessmentPeriod $period): bool => $this->app->make(ScorePeriodAccess::class)->canCalculate($user, $period));
    }
}
