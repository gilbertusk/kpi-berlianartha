<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Domain\RecordType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use InvalidArgumentException;

/**
 * The record types the system offers. A type is added only for a team need
 * whose rule is approved; candidates from the PRD appendix are not types.
 */
final class RecordTypeRegistry
{
    /** @var array<string, RecordType> */
    private array $types = [];

    /**
     * @param  iterable<RecordType>  $types
     */
    public function __construct(iterable $types)
    {
        foreach ($types as $type) {
            if (isset($this->types[$type->key()])) {
                throw new InvalidArgumentException("Duplicate record type [{$type->key()}].");
            }

            $this->types[$type->key()] = $type;
        }
    }

    /**
     * @return list<RecordType>
     */
    public function all(): array
    {
        return array_values($this->types);
    }

    public function get(string $key): RecordType
    {
        return $this->types[$key] ?? throw new BusinessRuleViolation('unknown_record_type', 'Jenis catatan tidak dikenal.');
    }
}
