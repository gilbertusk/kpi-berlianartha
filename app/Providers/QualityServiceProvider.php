<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Quality\Application\QualityAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class QualityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(QualityAccess::class)->needs('$qcUnitCode')->giveConfig('quality.qc_unit_code');
    }

    public function boot(): void
    {
        Gate::define('quality.view', fn (User $user): bool => $this->app->make(QualityAccess::class)->canView($user));
    }
}
