<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * Completing a reset proves control of the mailbox, so it also verifies
     * the email of administrator-provisioned accounts. Deactivated accounts
     * cannot set a new password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => __('Akun ini tidak aktif. Hubungi administrator.'),
            ]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}
