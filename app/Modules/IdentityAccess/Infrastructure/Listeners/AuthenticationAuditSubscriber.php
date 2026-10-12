<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Listeners;

use App\Models\User;
use App\Modules\IdentityAccess\Http\RequestActorFactory;
use App\Modules\Shared\Application\Audit\AuditContext;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;

/**
 * Records sensitive authentication events in the business audit (PRD FR 016).
 * Credentials are never written: failed attempts only reference an existing
 * account by its public ID, and the attempted email is not stored.
 */
final readonly class AuthenticationAuditSubscriber
{
    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            PasswordReset::class => 'onPasswordReset',
        ];
    }

    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;

        $this->transactions->run(function () use ($user, $event): void {
            $user->forceFill(['last_login_at' => CarbonImmutable::now()])->saveQuietly();

            $this->record('auth.login_succeeded', $user->public_id, $this->userContext($user), ['remember' => $event->remember]);
        });
    }

    public function onLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;

        $this->transactions->run(fn () => $this->record('auth.logout', $user->public_id, $this->userContext($user)));
    }

    public function onFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : $this->lookup($event->credentials);

        $this->transactions->run(fn () => $this->record(
            'auth.login_failed',
            $user->public_id ?? 'unknown',
            $this->anonymousContext(),
        ));
    }

    public function onLockout(Lockout $event): void
    {
        $this->transactions->run(fn () => $this->record('auth.lockout', 'unknown', $this->anonymousContext()));
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->transactions->run(fn () => $this->record('auth.password_reset_completed', $user->public_id, $this->userContext($user)));
    }

    /**
     * @param  array<string, mixed>|null  $after
     */
    private function record(string $action, string $entityId, AuditContext $context, ?array $after = null): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'user',
            entityId: $entityId,
            before: null,
            after: $after,
            reason: null,
            context: $context,
        ));
    }

    private function userContext(User $user): AuditContext
    {
        $requestId = RequestActorFactory::requestId($this->request());

        return new AuditContext(
            actorIdentifier: $user->public_id,
            actorType: 'user',
            actorRole: null,
            actorScope: null,
            source: 'web',
            sourceReference: 'authentication',
            correlationId: $requestId,
            requestId: $requestId,
        );
    }

    private function anonymousContext(): AuditContext
    {
        $requestId = RequestActorFactory::requestId($this->request());

        return new AuditContext(
            actorIdentifier: null,
            actorType: 'anonymous',
            actorRole: null,
            actorScope: null,
            source: 'web',
            sourceReference: 'authentication',
            correlationId: $requestId,
            requestId: $requestId,
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function lookup(array $credentials): ?User
    {
        $email = $credentials['email'] ?? null;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return User::query()->where('email', strtolower(trim($email)))->first();
    }

    private function request(): Request
    {
        return request();
    }
}
