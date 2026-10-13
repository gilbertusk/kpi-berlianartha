<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;

/**
 * One line of the actual entry list: the target, its final target value,
 * and the entry for the observation window being worked on (if any).
 */
final readonly class ActualRow
{
    public function __construct(
        public PeriodTarget $target,
        public ?string $targetValue,
        public ?KpiActual $actual,
        public ?KpiActualVersion $version,
        public int $entryCount,
    ) {}

    /**
     * @return 'missing'|'draft'|'submitted'|'verified'|'rejected'
     */
    public function state(): string
    {
        return $this->version === null ? 'missing' : $this->version->status->value;
    }

    public function acceptsEntry(): bool
    {
        return $this->version === null || $this->version->status->isEditable();
    }
}
