<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Target\Application\Policies\PeriodTargetPolicy;
use App\Modules\Target\Application\TargetSlotBuilder;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class TargetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Target slots are created in the transaction that opens a period.
        $this->app->tag([TargetSlotBuilder::class], PeriodAssignmentServiceProvider::LIFECYCLE_PARTICIPANTS);
    }

    public function boot(): void
    {
        Gate::policy(PeriodTarget::class, PeriodTargetPolicy::class);
    }
}
