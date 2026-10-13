<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use Brick\Math\BigDecimal;

/**
 * One calculated scorecard. When complete, the final score is the sum of the
 * rounded contributions, rounded by the policy's final rule, so the total
 * reconciles to the KPI lines.
 */
final readonly class ScorecardResult
{
    /**
     * @param  list<KpiLineResult>  $lines
     * @param  list<string>  $blockers  codes from ScorecardStatus::BLOCKERS
     */
    public function __construct(
        public ScorecardStatus $status,
        public array $blockers,
        public array $lines,
        public BigDecimal $assignedWeight,
        public BigDecimal $countedWeight,
        public BigDecimal $excludedWeight,
        public ?BigDecimal $contributionTotal,
        public ?BigDecimal $finalScore,
        public ?RatingBand $rating,
    ) {}

    public function isComplete(): bool
    {
        return $this->status === ScorecardStatus::Complete;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'blockers' => $this->blockers,
            'assigned_weight' => Exact::fixed($this->assignedWeight),
            'counted_weight' => Exact::fixed($this->countedWeight),
            'excluded_weight' => Exact::fixed($this->excludedWeight),
            'contribution_total' => $this->contributionTotal === null ? null : Exact::fixed($this->contributionTotal),
            'final_score' => $this->finalScore === null ? null : Exact::fixed($this->finalScore),
            'rating' => $this->rating?->key,
            'lines' => array_map(static fn (KpiLineResult $line): array => $line->toArray(), $this->lines),
        ];
    }
}
