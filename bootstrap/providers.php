<?php

use App\Providers\ActualEvidenceServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\IdentityAccessServiceProvider;
use App\Providers\KpiGovernanceServiceProvider;
use App\Providers\OrganizationServiceProvider;
use App\Providers\PeriodAssignmentServiceProvider;
use App\Providers\SharedServiceProvider;
use App\Providers\TargetServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    OrganizationServiceProvider::class,
    IdentityAccessServiceProvider::class,
    KpiGovernanceServiceProvider::class,
    PeriodAssignmentServiceProvider::class,
    TargetServiceProvider::class,
    ActualEvidenceServiceProvider::class,
    FortifyServiceProvider::class,
];
