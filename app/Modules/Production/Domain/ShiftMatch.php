<?php

declare(strict_types=1);

namespace App\Modules\Production\Domain;

/**
 * The shift a local moment belongs to, and the operational date the shift's
 * output is counted on.
 */
final readonly class ShiftMatch
{
    public function __construct(
        public string $shiftCode,
        public string $operationalDate,
        public string $calendarVersion,
    ) {}
}
