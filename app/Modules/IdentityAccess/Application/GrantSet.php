<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;

/**
 * All grants an account holds at one moment.
 */
final readonly class GrantSet
{
    /**
     * @param  list<Grant>  $grants
     */
    public function __construct(
        public string $businessDate,
        public array $grants,
    ) {}

    /**
     * @return list<Grant>
     */
    public function withCapability(Capability $capability): array
    {
        return array_values(array_filter(
            $this->grants,
            static fn (Grant $grant): bool => $grant->has($capability),
        ));
    }

    public function has(Capability $capability): bool
    {
        return $this->withCapability($capability) !== [];
    }

    public function hasRole(Role $role): bool
    {
        foreach ($this->grants as $grant) {
            if ($grant->role === $role) {
                return true;
            }
        }

        return false;
    }

    /**
     * Role assignments (not delegations) that the holder may delegate.
     *
     * @return list<Grant>
     */
    public function delegableAssignments(): array
    {
        return array_values(array_filter(
            $this->grants,
            static fn (Grant $grant): bool => $grant->source === Grant::SOURCE_ASSIGNMENT && $grant->role->isDelegable(),
        ));
    }
}
