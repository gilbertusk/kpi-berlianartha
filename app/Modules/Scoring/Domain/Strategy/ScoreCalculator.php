<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain\Strategy;

use Brick\Math\BigRational;
use InvalidArgumentException;

/**
 * The calculation of one allowlisted score strategy, registered under the
 * same key and version as its parameter definition in KpiGovernance
 * (ScoreStrategyRegistry). A version's calculation never changes; different
 * behaviour needs a new version on both sides.
 */
interface ScoreCalculator
{
    public function key(): string;

    public function version(): int;

    /**
     * Whether a final target is needed to score.
     */
    public function requiresTarget(): bool;

    /**
     * Every score value the definition states (floor and cap, band, yes/no,
     * or stage scores). They must be exact at the KPI's rounding scale, or
     * rounding would move a score past a signed bound.
     *
     * @param  array<string, mixed>  $parameters
     * @return list<BigRational>
     *
     * @throws InvalidArgumentException when the stored parameters are unusable
     */
    public function configuredScores(array $parameters): array;

    /**
     * @param  BigRational  $measured  the aggregated actual of the period
     * @param  array<string, mixed>  $parameters  normalized parameters of the KPI definition version
     *
     * @throws InvalidArgumentException when the stored parameters are unusable
     */
    public function calculate(BigRational $measured, ?BigRational $target, array $parameters): StrategyOutcome;
}
