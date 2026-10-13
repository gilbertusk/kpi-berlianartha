<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Application\Policies\AssessmentPeriodPolicy;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class PeriodAssignmentServiceProvider extends ServiceProvider
{
    /** Modules that take part in period transitions tag themselves with this. */
    public const LIFECYCLE_PARTICIPANTS = 'period.lifecycle_participants';

    public function register(): void
    {
        $this->app->when(PeriodService::class)
            ->needs('$participants')
            ->giveTagged(self::LIFECYCLE_PARTICIPANTS);
    }

    public function boot(): void
    {
        Gate::policy(AssessmentPeriod::class, AssessmentPeriodPolicy::class);

        Gate::define('periods.view', fn (User $user): bool => $this->app->make(AccessControl::class)
            ->allows($user, Capability::PeriodView));
    }
}
