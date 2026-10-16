<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

/**
 * Runtime status of a connection to a source system. PROVEN needs runtime
 * evidence against the real system; contract tests on fixtures never make
 * a connection PROVEN.
 */
enum ConnectionStatus: string
{
    case Proven = 'PROVEN';
    case Blocked = 'BLOCKED';
    case Deferred = 'DEFERRED';
}
