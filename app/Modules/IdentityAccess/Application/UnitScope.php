<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

/**
 * The organization units on which an account may use one capability. List
 * queries apply it as a filter instead of checking a policy per row.
 */
final readonly class UnitScope
{
    /**
     * @param  list<int>  $unitIds
     */
    public function __construct(
        public bool $all,
        public array $unitIds,
        public bool $self,
    ) {}

    public static function none(): self
    {
        return new self(false, [], false);
    }

    public function isEmpty(): bool
    {
        return ! $this->all && $this->unitIds === [] && ! $this->self;
    }

    /**
     * True when the scope reaches beyond the holder's own record.
     */
    public function reachesOthers(): bool
    {
        return $this->all || $this->unitIds !== [];
    }

    public function coversUnit(int $unitId): bool
    {
        return $this->all || in_array($unitId, $this->unitIds, true);
    }
}
