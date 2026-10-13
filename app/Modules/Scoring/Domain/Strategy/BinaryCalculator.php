<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain\Strategy;

use Brick\Math\BigRational;

/**
 * binary@1 (PRD §7.2 "Binary"): a measured value of 1 (Ya) scores
 * achieved_score, 0 (Tidak) scores not_achieved_score. Any other value,
 * for example a sum of several windows, is not scorable rather than
 * rounded to yes or no. The target is not used.
 */
final readonly class BinaryCalculator implements ScoreCalculator
{
    public function key(): string
    {
        return 'binary';
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
        return [Parameters::decimal($parameters, 'achieved_score'), Parameters::decimal($parameters, 'not_achieved_score')];
    }

    public function calculate(BigRational $measured, ?BigRational $target, array $parameters): StrategyOutcome
    {
        if ($measured->isEqualTo(1)) {
            return StrategyOutcome::scored(Parameters::decimal($parameters, 'achieved_score'), null, 'achieved');
        }

        if ($measured->isZero()) {
            return StrategyOutcome::scored(Parameters::decimal($parameters, 'not_achieved_score'), null, 'not_achieved');
        }

        return StrategyOutcome::invalid('invalid_binary_value');
    }
}
