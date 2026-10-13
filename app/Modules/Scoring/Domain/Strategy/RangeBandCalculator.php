<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain\Strategy;

use Brick\Math\BigRational;

/**
 * range_band@1 (PRD §7.2 "Range"): the score of the first configured band
 * [min, max) that holds the measured value; out_of_band_score when none
 * does. A value exactly on a boundary belongs to the band that starts
 * there. The target is not used.
 */
final readonly class RangeBandCalculator implements ScoreCalculator
{
    public function key(): string
    {
        return 'range_band';
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
        return [
            ...array_map(static fn (array $band): BigRational => Parameters::decimal($band, 'score'), Parameters::rows($parameters, 'bands')),
            Parameters::decimal($parameters, 'out_of_band_score'),
        ];
    }

    public function calculate(BigRational $measured, ?BigRational $target, array $parameters): StrategyOutcome
    {
        foreach (Parameters::rows($parameters, 'bands') as $index => $band) {
            $min = Parameters::decimal($band, 'min');
            $max = Parameters::decimal($band, 'max');

            if ($measured->isGreaterThanOrEqualTo($min) && $measured->isLessThan($max)) {
                return StrategyOutcome::scored(Parameters::decimal($band, 'score'), null, 'band_'.($index + 1));
            }
        }

        return StrategyOutcome::scored(Parameters::decimal($parameters, 'out_of_band_score'), null, 'out_of_band');
    }
}
