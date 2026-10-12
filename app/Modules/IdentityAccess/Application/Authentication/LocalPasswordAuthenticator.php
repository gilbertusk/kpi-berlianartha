<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Authentication;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Local email/password login for development and controlled fallback until
 * DEC-013 selects the company identity provider.
 */
final class LocalPasswordAuthenticator implements CredentialAuthenticator
{
    private ?string $dummyHash = null;

    public function __construct(private readonly Hasher $hasher) {}

    public function authenticate(string $identifier, string $password): ?User
    {
        $email = strtolower(trim($identifier));
        $user = $email === '' ? null : User::query()->where('email', $email)->first();

        if ($user === null) {
            // Spend comparable time on unknown accounts to avoid user enumeration.
            $this->hasher->check($password, $this->dummyHash ??= $this->hasher->make('unknown-account'));

            return null;
        }

        if (! $this->hasher->check($password, $user->password)) {
            return null;
        }

        // Checked after the password so the response does not reveal status.
        if (! $user->isActive()) {
            return null;
        }

        if ($this->hasher->needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return $user;
    }
}
