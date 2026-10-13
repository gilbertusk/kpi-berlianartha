<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Application;

/**
 * Input of a daily team review issue.
 */
final readonly class IssueDraft
{
    /**
     * @param  list<string>  $kpiDefinitionIds  public ids of the affected KPIs
     */
    public function __construct(
        public int $unitId,
        public string $reviewDate,
        public string $problem,
        public ?string $causeCode = null,
        public ?string $causeNote = null,
        public ?string $contextType = null,
        public ?string $contextReference = null,
        public array $kpiDefinitionIds = [],
    ) {}
}
