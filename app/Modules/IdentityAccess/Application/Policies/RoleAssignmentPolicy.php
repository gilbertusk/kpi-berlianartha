<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;

final readonly class RoleAssignmentPolicy
{
    public function __construct(private AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, Capability::RoleView);
    }

    public function create(User $user, ?User $recipient = null): bool
    {
        return ($recipient === null || $recipient->id !== $user->id)
            && $this->access->allows($user, Capability::RoleManage);
    }

    public function revoke(User $user, RoleAssignment $assignment): bool
    {
        return $assignment->user_id !== $user->id
            && $this->access->allows($user, Capability::RoleManage);
    }
}
