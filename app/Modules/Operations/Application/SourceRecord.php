<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

/**
 * One record as delivered by a source-system adapter.
 */
final readonly class SourceRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $recordType,
        public string $unitCode,
        public string $occurredOn,
        public array $attributes,
        public ?string $kpiCode = null,
    ) {}
}
