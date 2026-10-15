<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Production\Application\ProductionAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class ProductionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(ProductionAccess::class)->needs('$departmentCode')->giveConfig('production.department_code');
        $this->app->when(ProductionAccess::class)->needs('$ppicUnitCode')->giveConfig('production.ppic_unit_code');
        $this->app->when(ProductionAccess::class)->needs('$responsibilities')->giveConfig('production.responsibilities');
    }

    public function boot(): void
    {
        Gate::define('production.view', fn (User $user): bool => ! $this->app->make(ProductionAccess::class)->readScope($user)->isEmpty()
            || ! $this->app->make(ProductionAccess::class)->manageScope($user)->isEmpty());
        Gate::define('ppic.view', fn (User $user): bool => $this->app->make(ProductionAccess::class)->canViewBoard($user)
            || $this->app->make(ProductionAccess::class)->canManageBoard($user));
    }
}
