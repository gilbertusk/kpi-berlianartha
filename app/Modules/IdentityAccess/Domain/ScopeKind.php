<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain;

/**
 * How a role assignment is scoped. Roles carry capabilities; the scope says
 * where those capabilities apply (ADR-0009).
 */
enum ScopeKind: string
{
    /** Bound to an organization unit and its descendants. */
    case Organization = 'organization';

    /** Only the holder's own employee record. */
    case Self = 'self';

    /** Technical administration, not tied to an organization unit. */
    case System = 'system';
}
