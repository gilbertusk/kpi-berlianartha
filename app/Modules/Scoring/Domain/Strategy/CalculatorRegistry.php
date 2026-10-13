<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain\Strategy;

/**
 * Calculations for the allowlisted strategies, keyed "key@version" exactly
 * like KpiGovernance's ScoreStrategyRegistry. A test keeps both lists equal.
 */
final class CalculatorRegistry
{
    /** @var array<string, ScoreCalculator> */
    private array $calculators = [];

    public function __construct()
    {
        foreach ([
            new RatioToTargetCalculator,
            new RatioToTargetCalculator(inverse: true),
            new RangeBandCalculator,
            new BinaryCalculator,
            new MilestoneStageCalculator,
        ] as $calculator) {
            $this->calculators[$calculator->key().'@'.$calculator->version()] = $calculator;
        }
    }

    public function find(?string $key, ?int $version): ?ScoreCalculator
    {
        if ($key === null || $version === null) {
            return null;
        }

        return $this->calculators["{$key}@{$version}"] ?? null;
    }

    /**
     * @return list<string> "key@version"
     */
    public function references(): array
    {
        return array_keys($this->calculators);
    }
}
