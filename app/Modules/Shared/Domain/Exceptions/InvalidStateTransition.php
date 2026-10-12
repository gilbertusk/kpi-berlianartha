<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use App\Modules\Shared\Domain\ErrorCode;

final class InvalidStateTransition extends SafeDomainException
{
    public function __construct(string $currentState, string $targetState)
    {
        parent::__construct(
            ErrorCode::INVALID_STATE_TRANSITION,
            'Perubahan status tidak diizinkan.',
            422,
            [
                'current_state' => $currentState,
                'target_state' => $targetState,
            ],
        );
    }
}
