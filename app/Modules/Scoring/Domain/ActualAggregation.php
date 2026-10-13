<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use Brick\Math\BigRational;

/**
 * Combines the value entries of one target into the measured value of the
 * period, using the aggregation signed on the KPI definition
 * (Vocabulary::AGGREGATIONS). N/A entries never reach this point.
 *
 * ratio_of_sums needs a numerator and denominator per observation, which
 * actuals do not capture yet, so it is reported as unsupported instead of
 * being approximated.
 */
final class ActualAggregation
{
    /** @var list<string> */
    public const SUPPORTED = ['sum', 'average', 'last_value', 'minimum', 'maximum', 'count'];

    /**
     * @param  non-empty-list<BigRational>  $values  in observation-window order
     * @return BigRational|null null when the aggregation is not supported
     */
    public static function aggregate(?string $aggregation, array $values): ?BigRational
    {
        return match ($aggregation) {
            'sum' => Exact::sum(...$values),
            'average' => Exact::sum(...$values)->dividedBy(count($values)),
            'last_value' => $values[count($values) - 1],
            'minimum' => BigRational::min(...$values),
            'maximum' => BigRational::max(...$values),
            'count' => BigRational::of(count($values)),
            default => null,
        };
    }
}
