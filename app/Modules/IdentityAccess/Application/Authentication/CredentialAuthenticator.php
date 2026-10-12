<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Authentication;

use App\Models\User;

/**
 * Authentication adapter boundary (ADR-0009, DEC-013). Local password login is
 * the only implementation; an SSO adapter would resolve the same User and
 * must reject non-active accounts in the same way. Authorization never depends
 * on which adapter signed the user in.
 */
interface CredentialAuthenticator
{
    /**
     * Returns the account when the credentials are valid and the account is
     * active, otherwise null. Implementations must not reveal which check
     * failed.
     */
    public function authenticate(string $identifier, string $password): ?User;
}
