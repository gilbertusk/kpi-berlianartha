<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

enum ErrorCode: string
{
    case CONCURRENCY_CONFLICT = 'concurrency_conflict';
    case IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
    case INVALID_STATE_TRANSITION = 'invalid_state_transition';
    case NOT_FOUND = 'not_found';
    case FORBIDDEN = 'forbidden';
    case VALIDATION_FAILED = 'validation_failed';
    case UNEXPECTED = 'unexpected_error';
}
