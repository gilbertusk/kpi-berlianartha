<?php

use App\Providers\AccountingServiceProvider;
use App\Providers\ActualEvidenceServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\IdentityAccessServiceProvider;
use App\Providers\KpiGovernanceServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\OperationsServiceProvider;
use App\Providers\OrganizationServiceProvider;
use App\Providers\PeriodAssignmentServiceProvider;
use App\Providers\ProductionServiceProvider;
use App\Providers\QualityServiceProvider;
use App\Providers\ReportingServiceProvider;
use App\Providers\ScoringServiceProvider;
use App\Providers\SharedServiceProvider;
use App\Providers\TargetServiceProvider;
use App\Providers\TeamReviewServiceProvider;
use App\Providers\WorkflowServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    OrganizationServiceProvider::class,
    IdentityAccessServiceProvider::class,
    KpiGovernanceServiceProvider::class,
    PeriodAssignmentServiceProvider::class,
    TargetServiceProvider::class,
    ActualEvidenceServiceProvider::class,
    ScoringServiceProvider::class,
    WorkflowServiceProvider::class,
    NotificationServiceProvider::class,
    TeamReviewServiceProvider::class,
    ReportingServiceProvider::class,
    OperationsServiceProvider::class,
    AccountingServiceProvider::class,
    ProductionServiceProvider::class,
    QualityServiceProvider::class,
    FortifyServiceProvider::class,
];
