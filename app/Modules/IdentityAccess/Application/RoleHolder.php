<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Role;

/**
 * An active account holding an organization-scoped role on one date.
 */
final readonly class RoleHolder
{
    public function __construct(
        public Role $role,
        public int $userId,
        public string $userName,
        public int $unitId,
        public string $assignmentPublicId,
    ) {}
}
