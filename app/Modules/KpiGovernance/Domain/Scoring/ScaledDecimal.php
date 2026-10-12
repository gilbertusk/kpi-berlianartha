<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain\Scoring;

/**
 * Parses decimal strings into integers scaled by 10^4 so configuration can
 * be compared exactly. Floats are never used for scoring parameters
 * (ADR-0008).
 */
final class ScaledDecimal
{
    public const SCALE = 4;

    public static function parse(mixed $value, bool $allowNegative = false): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $pattern = $allowNegative ? '/^(-?)(\d{1,12})(?:\.(\d{1,4}))?$/' : '/^()(\d{1,12})(?:\.(\d{1,4}))?$/';

        if (preg_match($pattern, trim($value), $matches) !== 1) {
            return null;
        }

        $scaled = (int) $matches[2] * 10_000 + (int) str_pad($matches[3] ?? '', self::SCALE, '0');

        return $matches[1] === '-' ? -$scaled : $scaled;
    }

    public static function normalize(mixed $value, bool $allowNegative = false): ?string
    {
        $scaled = self::parse($value, $allowNegative);

        if ($scaled === null) {
            return null;
        }

        $sign = $scaled < 0 ? '-' : '';
        $absolute = abs($scaled);
        $fraction = rtrim(str_pad((string) ($absolute % 10_000), self::SCALE, '0', STR_PAD_LEFT), '0');

        return $sign.intdiv($absolute, 10_000).($fraction === '' ? '' : ".{$fraction}");
    }
}
