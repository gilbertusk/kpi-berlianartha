<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Estimated versus actual of one cost component, computed exactly. The
 * variance is actual minus estimate; the relative variance is the variance
 * over the estimate and does not exist for a zero estimate. These are the
 * arithmetic of a comparison, not a KPI score: how an "estimation accuracy"
 * KPI scores them is the KPI definition's rule (DEC-006).
 */
final readonly class CostingComparison
{
    private function __construct(
        public string $estimated,
        public string $actual,
        public string $variance,
        public ?string $variancePercent,
    ) {}

    public static function of(string $estimated, string $actual): self
    {
        $estimate = BigDecimal::of($estimated);
        $real = BigDecimal::of($actual);
        $variance = $real->minus($estimate);

        $percent = $estimate->isZero()
            ? null
            : (string) $variance->multipliedBy(100)->dividedBy($estimate, 4, RoundingMode::HalfUp);

        return new self((string) $estimate->toScale(4), (string) $real->toScale(4), (string) $variance->toScale(4), $percent);
    }
}
