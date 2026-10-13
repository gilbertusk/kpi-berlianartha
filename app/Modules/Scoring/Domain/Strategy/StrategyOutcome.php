<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain\Strategy;

use App\Modules\Scoring\Domain\KpiDataStatus;
use Brick\Math\BigRational;

/**
 * The result of one strategy for one measured value: an exact, not yet
 * rounded score (and the achievement where the strategy defines one), an
 * N/A decided by a signed zero rule, or a value the strategy cannot score.
 * The note is a stable code that explains which branch applied.
 */
final readonly class StrategyOutcome
{
    private function __construct(
        public KpiDataStatus $status,
        public ?BigRational $score,
        public ?BigRational $achievement,
        public ?string $note,
    ) {}

    public static function scored(BigRational $score, ?BigRational $achievement = null, ?string $note = null): self
    {
        return new self(KpiDataStatus::Scored, $score, $achievement, $note);
    }

    public static function notApplicable(string $note): self
    {
        return new self(KpiDataStatus::RuleNotApplicable, null, null, $note);
    }

    public static function invalid(string $note): self
    {
        return new self(KpiDataStatus::InvalidInput, null, null, $note);
    }
}
