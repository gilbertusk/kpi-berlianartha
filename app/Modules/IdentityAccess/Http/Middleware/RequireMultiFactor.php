<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA enforcement switch (DEC-013 is still OPEN, so it is off by default).
 * When IDENTITY_MFA_REQUIRED=true, an account without confirmed two-factor
 * authentication is sent to the security settings page before it can use
 * the application.
 */
final class RequireMultiFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (Config::boolean('identity.mfa_required') && $user instanceof User && $user->two_factor_confirmed_at === null) {
            return redirect()->route('security.edit')
                ->with('status', 'mfa-required');
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
