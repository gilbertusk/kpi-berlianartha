<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Http\RequestActorFactory;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that is no longer active, both before the
 * request runs (session from an earlier login) and after it (login paths that
 * bypass the credential authenticator, such as passkeys or 2FA completion).
 *
 * It also resets per-request authorization memoization so a long-lived
 * worker never reuses grants from a previous request.
 */
final readonly class EnsureAccountIsActive
{
    public function __construct(
        private AccessControl $access,
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private RequestActorFactory $actors,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->access->flush();

        if ($this->terminateInactiveSession($request)) {
            return $this->denied($request);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($this->terminateInactiveSession($request)) {
            return $this->denied($request);
        }

        return $response;
    }

    /**
     * @phpstan-impure Depends on the authenticated user, which the request may change.
     */
    private function terminateInactiveSession(Request $request): bool
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || $user->isActive()) {
            return false;
        }

        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(
            action: 'auth.session_terminated',
            entityType: 'user',
            entityId: $user->public_id,
            before: null,
            after: ['cause' => 'account_inactive'],
            reason: null,
            context: $this->actors->forUser($user)->audit,
        )));

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return true;
    }

    private function denied(Request $request): Response
    {
        $message = 'Akun Anda tidak aktif. Hubungi administrator.';

        if ($request->expectsJson()) {
            return new JsonResponse(['error' => ['code' => 'forbidden', 'message' => $message]], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
