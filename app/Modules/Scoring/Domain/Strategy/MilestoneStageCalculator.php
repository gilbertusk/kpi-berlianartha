<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain\Strategy;

use Brick\Math\BigRational;

/**
 * milestone_stage@1 (PRD §7.2 "Milestone"): the measured value is the stage
 * number reached (1 to the number of stages, as targets and actuals are
 * entered) and scores that stage's signed score. Any other value is not
 * scorable. The target is not used.
 */
final readonly class MilestoneStageCalculator implements ScoreCalculator
{
    public function key(): string
    {
        return 'milestone_stage';
    }

    public function version(): int
    {
        return 1;
    }

    public function requiresTarget(): bool
    {
        return false;
    }

    public function configuredScores(array $parameters): array
    {
        return array_map(static fn (array $stage): BigRational => Parameters::decimal($stage, 'score'), Parameters::rows($parameters, 'stages'));
    }

    public function calculate(BigRational $measured, ?BigRational $target, array $parameters): StrategyOutcome
    {
        $stages = Parameters::rows($parameters, 'stages');

        if (! $measured->getDenominator()->isEqualTo(1) || $measured->isLessThan(1) || $measured->isGreaterThan(count($stages))) {
            return StrategyOutcome::invalid('invalid_milestone_stage');
        }

        $stage = $measured->getNumerator()->toInt();

        return StrategyOutcome::scored(Parameters::decimal($stages[$stage - 1], 'score'), null, "stage_{$stage}");
    }
}
