<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use App\Modules\Shared\Domain\Exceptions\InvalidStateTransition;

final class StateTransitionGuard
{
    /**
     * @param  array<string, list<string>>  $allowedTransitions
     */
    public function assertAllowed(string $currentState, string $targetState, array $allowedTransitions): void
    {
        if (! in_array($targetState, $allowedTransitions[$currentState] ?? [], true)) {
            throw new InvalidStateTransition($currentState, $targetState);
        }
    }
}
