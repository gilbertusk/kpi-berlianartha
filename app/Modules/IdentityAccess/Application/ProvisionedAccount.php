<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;

/**
 * Result of provisioning: the account always exists after commit; the setup
 * link may still need to be resent when sending was throttled or failed.
 */
final readonly class ProvisionedAccount
{
    public function __construct(
        public User $user,
        public bool $setupLinkSent,
    ) {}
}
