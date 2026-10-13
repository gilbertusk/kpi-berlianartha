<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Workflow\Application\WorkflowAccess;
use App\Modules\Workflow\Application\WorkflowLifecycle;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([WorkflowLifecycle::class], PeriodAssignmentServiceProvider::LIFECYCLE_PARTICIPANTS);
    }

    public function boot(): void
    {
        Gate::define('approvals.view', fn (User $user): bool => $this->app->make(AccessControl::class)->allowsAny($user, WorkflowAccess::CAPABILITIES));
        Gate::define('approvalBypasses.manage', fn (User $user): bool => $this->app->make(AccessControl::class)->allows($user, Capability::ApprovalRouteManage));

        Gate::define('viewWorkflow', fn (User $user, AssessmentPeriod $period): bool => $this->app->make(WorkflowAccess::class)->canViewPeriod($user, $period));
        Gate::define('viewReview', fn (User $user, ScorecardReview $review): bool => $this->app->make(WorkflowAccess::class)->canViewReview($user, $review));
    }
}
