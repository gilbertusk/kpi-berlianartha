<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

use InvalidArgumentException;

/**
 * A kind of operational record. Each type defines its fields and rules;
 * storage, access, audit, voiding, and screens are shared, so a new team
 * need adds a type, not a module.
 */
interface RecordType
{
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /**
     * @return list<FieldSpec>
     */
    public function fields(): array;

    /**
     * Validates and normalizes the attributes of a new record.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|null>
     *
     * @throws InvalidArgumentException with a message safe to show
     */
    public function normalize(array $input): array;

    /**
     * The identity of the record inside its source, derived from its
     * attributes, so the same source record is registered once.
     *
     * @param  array<string, string|int|null>  $attributes
     */
    public function sourceReference(array $attributes): string;

    /**
     * Roles whose `operational_record.view` grant may read this type. Cost
     * figures are not readable by every role that reads design revisions.
     *
     * @return list<string> role values
     */
    public function readerRoles(): array;
}
