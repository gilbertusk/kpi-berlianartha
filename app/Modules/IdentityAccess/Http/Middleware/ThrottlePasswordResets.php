<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit for the Fortify password-reset routes, which Fortify registers
 * without one: a brake on mail flooding and on probing addresses (Step 18).
 * The limit itself is the `password-reset` limiter.
 */
final class ThrottlePasswordResets
{
    public const LIMITER = 'password-reset';

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs('password.email', 'password.update')) {
            return $this->throttle->handle($request, $next, self::LIMITER);
        }

        return $next($request);
    }
}
