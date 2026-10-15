<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Operations\Application\RecordTypeRegistry;
use App\Modules\Operations\Domain\Types\CostingComparisonType;
use App\Modules\Operations\Domain\Types\DesignRevisionType;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    /**
     * Record types with an approved rule. Sales, Customer Service, PPIC
     * handover, Purchasing, HR, and GA have none yet and use the generic
     * KPI platform.
     */
    private const TYPES = [
        DesignRevisionType::class,
        CostingComparisonType::class,
    ];

    public function register(): void
    {
        $this->app->singleton(RecordTypeRegistry::class, fn (Application $app): RecordTypeRegistry => new RecordTypeRegistry(array_map(
            static fn (string $type): object => $app->make($type),
            self::TYPES,
        )));
    }

    public function boot(): void
    {
        Gate::define('operations.view', fn (User $user): bool => $this->app->make(AccessControl::class)->allowsAny($user, [Capability::OperationalRecordView, Capability::OperationalRecordManage]));
    }
}
