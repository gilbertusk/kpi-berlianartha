<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Database\Seeders\DevelopmentDemoSeeder;
use Database\Seeders\DevelopmentIdentitySeeder;
use Database\Seeders\OrganizationReferenceSeeder;

test('local demo seed creates a complete synthetic workflow and is idempotent', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    $this->seed(OrganizationReferenceSeeder::class);
    $this->seed(DevelopmentIdentitySeeder::class);

    $this->seed(DevelopmentDemoSeeder::class);

    expect(KpiDefinition::query()->where('code', 'like', 'DEMO-%')->count())->toBe(6)
        ->and(KpiDefinitionVersion::query()
            ->whereHas('definition', fn ($query) => $query->where('code', 'like', 'DEMO-%'))
            ->where('status', LifecycleStatus::Published->value)
            ->count())->toBe(6)
        ->and(KpiTemplate::query()->where('code', 'like', 'DEMO-%')->count())->toBe(3)
        ->and(AssessmentPeriod::query()->where('code', DevelopmentDemoSeeder::PERIOD_CODE)->count())->toBe(1)
        ->and(PeriodTarget::query()->whereHas('period', fn ($query) => $query->where('code', DevelopmentDemoSeeder::PERIOD_CODE))->count())->toBe(12)
        ->and(KpiActual::query()->whereHas('target.period', fn ($query) => $query->where('code', DevelopmentDemoSeeder::PERIOD_CODE))->count())->toBe(12);

    $counts = [
        KpiDefinition::query()->count(),
        KpiTemplate::query()->count(),
        AssessmentPeriod::query()->count(),
        PeriodTarget::query()->count(),
        KpiActual::query()->count(),
    ];

    $this->seed(DevelopmentDemoSeeder::class);

    expect([
        KpiDefinition::query()->count(),
        KpiTemplate::query()->count(),
        AssessmentPeriod::query()->count(),
        PeriodTarget::query()->count(),
        KpiActual::query()->count(),
    ])->toBe($counts);
});

test('local demo seed refuses every non-local environment', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    expect(fn () => app()->call([app(DevelopmentDemoSeeder::class), 'run']))->toThrow(RuntimeException::class);
})->with(['testing', 'staging', 'production']);
