<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

/**
 * Input for creating or editing a draft period. Values are raw; the service
 * validates them so every entry point gets the same rules.
 */
final readonly class PeriodSetup
{
    /**
     * @param  array<string, string|null>  $deadlines  DeadlineStage value => YYYY-MM-DD or empty
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $type,
        public string $startsOn,
        public string $endsOn,
        public int $unitId,
        public ?string $referenceDate,
        public ?string $notes,
        public array $deadlines,
    ) {}
}
