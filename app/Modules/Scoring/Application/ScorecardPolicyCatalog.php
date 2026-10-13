<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Application;

use App\Modules\Scoring\Domain\ScorecardPolicy;
use InvalidArgumentException;

/**
 * The approved scorecard policies (DEC-004). While DEC-004 is open the
 * catalog is empty and official scoring is blocked: there is no fallback
 * policy.
 */
interface ScorecardPolicyCatalog
{
    /**
     * @return list<ScorecardPolicy>
     *
     * @throws InvalidArgumentException when the configured policies are invalid
     */
    public function all(): array;

    /**
     * The policy in force on a business date, or null.
     *
     * @throws InvalidArgumentException when the configured policies are invalid
     */
    public function inForceOn(string $businessDate): ?ScorecardPolicy;
}
