<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Livewire;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\AccessTarget;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Http\RequestActorFactory;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\SafeDomainException;
use Closure;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Base class for administration Livewire pages. Components stay thin: they
 * authorize, call an application service, and show safe domain errors.
 */
abstract class AdministrationPage extends Component
{
    #[Locked]
    public ?string $flash = null;

    protected function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function actorFor(Capability $capability, ?AccessTarget $target = null): Actor
    {
        return app(RequestActorFactory::class)->forCapability($this->currentUser(), $capability, $target);
    }

    protected function selfActor(): Actor
    {
        return app(RequestActorFactory::class)->forUser($this->currentUser());
    }

    protected function access(): AccessControl
    {
        return app(AccessControl::class);
    }

    protected function businessToday(): string
    {
        return $this->access()->businessToday();
    }

    /**
     * Runs a use case; a safe domain error is shown under $errorKey instead
     * of failing the request. Authorization errors still result in 403.
     */
    protected function attempt(string $errorKey, string $successMessage, Closure $operation): bool
    {
        $this->resetErrorBag($errorKey);
        $this->flash = null;

        try {
            $operation();
        } catch (SafeDomainException $exception) {
            $this->addError($errorKey, $exception->safeMessage());

            return false;
        }

        $this->flash = $successMessage;

        return true;
    }
}
