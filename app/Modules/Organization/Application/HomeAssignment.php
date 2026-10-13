<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

/**
 * An employee's home team and position as they were on one business date.
 */
final readonly class HomeAssignment
{
    public function __construct(
        public int $assignmentId,
        public int $employeeId,
        public string $employeeNumber,
        public string $fullName,
        public ?int $userId,
        public int $unitId,
        public ?string $positionTitle,
    ) {}
}
