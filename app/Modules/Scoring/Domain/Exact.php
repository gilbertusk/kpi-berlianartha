<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Exact arithmetic helpers (ADR-0008). Every intermediate value is a
 * rational number, so a ratio such as 90 ÷ 97 is never approximated.
 * Rounding happens only where a signed rule says so ({@see RoundingRule}).
 * Floats are never used.
 */
final class Exact
{
    /** Decimal strings as stored in numeric columns and score parameters. */
    private const DECIMAL = '/^-?\d{1,18}(?:\.\d{1,8})?$/';

    /** Display text keeps at most this many decimals for a value that does not terminate. */
    private const DISPLAY_SCALE = 8;

    public static function parse(string $value): BigRational
    {
        $value = trim($value);

        if (preg_match(self::DECIMAL, $value) !== 1) {
            throw new InvalidArgumentException("Bukan angka desimal: {$value}");
        }

        return BigDecimal::of($value)->toBigRational();
    }

    public static function parseNullable(?string $value): ?BigRational
    {
        return $value === null ? null : self::parse($value);
    }

    public static function sum(BigRational ...$values): BigRational
    {
        $total = BigRational::zero();

        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    /**
     * Text for display and snapshots: the exact decimal when it terminates
     * within 12 decimals ("12.5"), otherwise "≈" and 8 decimals
     * ("≈92.78350515"). The approximation is display only; scores are always
     * computed from the exact value, which {@see fraction()} records.
     */
    public static function describe(BigRational $value): string
    {
        try {
            return $value->toScale(12)->strippedOfTrailingZeros()->toString();
        } catch (RoundingNecessaryException) {
            return '≈'.$value->toScale(self::DISPLAY_SCALE, RoundingMode::HalfEven)->toString();
        }
    }

    public static function describeNullable(?BigRational $value): ?string
    {
        return $value === null ? null : self::describe($value);
    }

    /**
     * The exact value as "numerator/denominator" (or an integer), for traces.
     */
    public static function fraction(BigRational $value): string
    {
        return $value->getDenominator()->isEqualTo(1)
            ? $value->getNumerator()->toString()
            : $value->getNumerator()->toString().'/'.$value->getDenominator()->toString();
    }

    /**
     * A stored numeric(…, 4) value. The value must already be rounded to at
     * most four decimals by a rule; this never rounds.
     */
    public static function fixed(BigDecimal $value): string
    {
        return $value->toScale(4)->toString();
    }
}
