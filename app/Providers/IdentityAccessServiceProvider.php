<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\Authentication\CredentialAuthenticator;
use App\Modules\IdentityAccess\Application\Authentication\LocalPasswordAuthenticator;
use App\Modules\IdentityAccess\Application\Policies\AccountPolicy;
use App\Modules\IdentityAccess\Application\Policies\DelegationPolicy;
use App\Modules\IdentityAccess\Application\Policies\RoleAssignmentPolicy;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\IdentityAccess\Http\Middleware\RequireMultiFactor;
use App\Modules\IdentityAccess\Infrastructure\Console\RecordDelegationExpiryCommand;
use App\Modules\IdentityAccess\Infrastructure\Listeners\AuthenticationAuditSubscriber;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Livewire\Livewire;

final class IdentityAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RoleCapabilityMatrix::class);

        // Grants are memoized per request/job; never share them across requests.
        $this->app->scoped(AccessControl::class);

        $this->app->bind(CredentialAuthenticator::class, function (Application $app): CredentialAuthenticator {
            $provider = Config::string('identity.provider', 'local');

            return match ($provider) {
                'local' => $app->make(LocalPasswordAuthenticator::class),
                // DEC-013: no SSO adapter is implemented yet; fail loudly instead of guessing.
                default => throw new InvalidArgumentException("Identity provider [{$provider}] is not implemented."),
            };
        });
    }

    public function boot(): void
    {
        Gate::policy(User::class, AccountPolicy::class);
        Gate::policy(RoleAssignment::class, RoleAssignmentPolicy::class);
        Gate::policy(Delegation::class, DelegationPolicy::class);

        Gate::define('access-review.view', fn (User $user): bool => $this->app->make(AccessControl::class)
            ->allows($user, Capability::AccessReviewView));

        Gate::define('administration.view', fn (User $user): bool => $this->app->make(AccessControl::class)->allowsAny($user, [
            Capability::OrganizationView,
            Capability::AccountView,
            Capability::RoleView,
            Capability::DelegationView,
            Capability::AccessReviewView,
        ]) || Gate::forUser($user)->allows('viewAny', Delegation::class));

        Event::subscribe(AuthenticationAuditSubscriber::class);

        // Re-applied on Livewire updates, not only on the initial page load.
        Livewire::addPersistentMiddleware([RequireMultiFactor::class]);

        if ($this->app->runningInConsole()) {
            $this->commands([RecordDelegationExpiryCommand::class]);
        }
    }
}
