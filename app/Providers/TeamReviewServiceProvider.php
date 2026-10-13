<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\TeamReview\Application\TeamReviewAccess;
use App\Modules\TeamReview\Application\TeamReviewService;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class TeamReviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(TeamReviewService::class)->needs('$completionEvidenceRequired')->giveConfig('team_review.completion_evidence_required');
    }

    public function boot(): void
    {
        Gate::define('team-review.view', fn (User $user): bool => $this->app->make(TeamReviewAccess::class)->canUse($user));
        Gate::define('team-review.manage', fn (User $user): bool => $this->app->make(AccessControl::class)->allows($user, Capability::TeamReviewManage));
        Gate::define('viewCorrectiveAction', fn (User $user, CorrectiveAction $action): bool => $this->app->make(TeamReviewAccess::class)->canViewAction($user, $action));
        Gate::define('team.view', fn (User $user): bool => $this->app->make(AccessControl::class)->allows($user, Capability::TeamReviewManage));
    }
}
