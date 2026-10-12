<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\Policies\EmployeePolicy;
use App\Modules\Organization\Application\Policies\OrganizationUnitPolicy;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Memoizes hierarchy snapshots for one request/job only.
        $this->app->scoped(OrganizationDirectory::class);
    }

    public function boot(): void
    {
        Gate::policy(OrganizationUnit::class, OrganizationUnitPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
    }
}
