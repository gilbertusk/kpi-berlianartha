<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

/**
 * Result of an authorization check. The matching grant is kept so the use
 * case can record which role/delegation authorized the action.
 */
final readonly class AccessDecision
{
    public const ACCOUNT_INACTIVE = 'account_inactive';

    public const NO_GRANT = 'no_grant';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const SELF_ACTION = 'self_action';

    private function __construct(
        public bool $allowed,
        public ?Grant $grant,
        public ?string $denialReason,
    ) {}

    public static function allow(Grant $grant): self
    {
        return new self(true, $grant, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
