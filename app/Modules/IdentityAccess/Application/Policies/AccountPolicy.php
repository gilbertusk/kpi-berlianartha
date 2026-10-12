<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;

/**
 * Technical account administration (System Admin). Nobody may change the
 * status or employee link of their own account.
 */
final readonly class AccountPolicy
{
    public function __construct(private AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, Capability::AccountView);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, Capability::AccountManage);
    }

    public function update(User $user, User $account): bool
    {
        return $user->id !== $account->id
            && $this->access->allows($user, Capability::AccountManage);
    }
}
