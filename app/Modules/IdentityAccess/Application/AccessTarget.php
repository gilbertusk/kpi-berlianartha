<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

/**
 * What a capability is being exercised on. Build it through
 * {@see AccessControl::unitTarget()} or {@see AccessControl::employeeTarget()}
 * so the organization lookup uses the same business date as the decision.
 */
final readonly class AccessTarget
{
    /**
     * @param  list<int>  $unitIds
     */
    private function __construct(
        public ?int $unitId,
        public ?int $employeeId,
        public ?int $employeeUserId,
        public array $unitIds,
    ) {}

    public static function unit(int $unitId): self
    {
        return new self($unitId, null, null, [$unitId]);
    }

    /**
     * @param  list<int>  $unitIds  units the employee belongs to on the decision date
     */
    public static function employee(int $employeeId, ?int $employeeUserId, array $unitIds): self
    {
        return new self(null, $employeeId, $employeeUserId, $unitIds);
    }

    public function isEmployee(): bool
    {
        return $this->employeeId !== null;
    }
}
