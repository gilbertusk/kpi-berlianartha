<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * The outcome for one KPI line with every intermediate value, so the line
 * can be reconciled by hand: measured value, target, achievement, exact
 * score, rounded KPI score, contribution, and which entries were used.
 */
final readonly class KpiLineResult
{
    /**
     * @param  list<array<string, mixed>>  $trace  one row per actual entry and the decision taken
     */
    public function __construct(
        public KpiLineInput $input,
        public KpiDataStatus $status,
        public ?string $note,
        public ?BigRational $measured,
        public ?BigRational $target,
        public ?BigRational $achievement,
        public ?BigRational $rawScore,
        public ?BigDecimal $score,
        public ?BigDecimal $contribution,
        public array $trace,
    ) {}

    public function withContribution(BigDecimal $contribution): self
    {
        return new self($this->input, $this->status, $this->note, $this->measured, $this->target, $this->achievement, $this->rawScore, $this->score, $contribution, $this->trace);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kpi_code' => $this->input->kpiCode,
            'status' => $this->status->value,
            'note' => $this->note,
            'measured' => Exact::describeNullable($this->measured),
            'measured_exact' => $this->measured === null ? null : Exact::fraction($this->measured),
            'target' => Exact::describeNullable($this->target),
            'achievement' => Exact::describeNullable($this->achievement),
            'achievement_exact' => $this->achievement === null ? null : Exact::fraction($this->achievement),
            'raw_score_exact' => $this->rawScore === null ? null : Exact::fraction($this->rawScore),
            'score' => $this->score === null ? null : Exact::fixed($this->score),
            'contribution' => $this->contribution === null ? null : Exact::fixed($this->contribution),
            'trace' => $this->trace,
        ];
    }
}
