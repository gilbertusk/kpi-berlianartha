<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain;

use InvalidArgumentException;

/**
 * A scorecard weight in percentage points with at most four decimals,
 * held as an integer number of 0.0001 units so sums are exact (no float).
 */
final readonly class Weight
{
    public const SCALE = 4;

    private const UNIT = 10_000;

    public const TOTAL_REQUIRED_UNITS = 100 * self::UNIT;

    private function __construct(public int $units) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Bobot harus berupa angka desimal dengan maksimal 4 angka di belakang koma.');
        }

        $units = (int) $matches[1] * self::UNIT + (int) str_pad($matches[2] ?? '', self::SCALE, '0');

        if ($units <= 0 || $units > self::TOTAL_REQUIRED_UNITS) {
            throw new InvalidArgumentException('Bobot harus lebih dari 0 dan paling besar 100.');
        }

        return new self($units);
    }

    public static function fromUnits(int $units): self
    {
        return new self($units);
    }

    /**
     * @param  iterable<self>  $weights
     */
    public static function sumUnits(iterable $weights): int
    {
        $total = 0;

        foreach ($weights as $weight) {
            $total += $weight->units;
        }

        return $total;
    }

    public static function formatUnits(int $units): string
    {
        $whole = intdiv($units, self::UNIT);
        $fraction = rtrim(str_pad((string) ($units % self::UNIT), self::SCALE, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : "{$whole}.{$fraction}";
    }

    public function toDecimalString(): string
    {
        return sprintf('%d.%04d', intdiv($this->units, self::UNIT), $this->units % self::UNIT);
    }

    public function format(): string
    {
        return self::formatUnits($this->units);
    }
}
