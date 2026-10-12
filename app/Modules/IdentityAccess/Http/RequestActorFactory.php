<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\AccessTarget;
use App\Modules\IdentityAccess\Application\Grant;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Builds the audit actor for a web request, including which grant (role
 * assignment or delegation) authorized the action.
 */
final readonly class RequestActorFactory
{
    public function __construct(
        private AccessControl $access,
        private Request $request,
    ) {}

    public function forCapability(User $user, Capability $capability, ?AccessTarget $target = null): Actor
    {
        return $this->forUser($user, $this->access->decide($user, $capability, $target)->grant);
    }

    public function forUser(User $user, ?Grant $grant = null): Actor
    {
        $requestId = self::requestId($this->request);

        return new Actor($user->id, new AuditContext(
            actorIdentifier: $user->public_id,
            actorType: 'user',
            actorRole: $grant?->role->value,
            actorScope: $grant?->toAuditScope(),
            source: 'web',
            sourceReference: $this->request->route()?->getName(),
            correlationId: $requestId,
            requestId: $requestId,
        ));
    }

    public static function requestId(Request $request): string
    {
        $existing = $request->attributes->get('request_id');

        if (is_string($existing)) {
            return $existing;
        }

        $header = $request->headers->get('X-Request-Id');
        $requestId = is_string($header) && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $header) === 1
            ? $header
            : (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);

        return $requestId;
    }
}
