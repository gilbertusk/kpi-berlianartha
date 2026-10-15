<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Accounting\Application\ArAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(ArAccess::class)->needs('$departmentCode')->giveConfig('accounting.department_code');
    }

    public function boot(): void
    {
        Gate::define('accounting.view', fn (User $user): bool => ! $this->app->make(ArAccess::class)->readScope($user)->isEmpty()
            || ! $this->app->make(ArAccess::class)->manageScope($user)->isEmpty());
    }
}
