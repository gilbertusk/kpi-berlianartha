<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use App\Modules\Shared\Domain\ErrorCode;

/**
 * A request that is well-formed but breaks a domain rule. The message must be
 * safe to show to the user; the rule key is a stable identifier for tests and
 * monitoring.
 */
final class BusinessRuleViolation extends SafeDomainException
{
    public function __construct(public readonly string $rule, string $safeMessage)
    {
        parent::__construct(
            ErrorCode::VALIDATION_FAILED,
            $safeMessage,
            422,
            ['rule' => $rule],
        );
    }
}
