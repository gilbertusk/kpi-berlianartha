<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain\Strategy;

use App\Modules\Scoring\Domain\Exact;
use Brick\Math\BigRational;
use InvalidArgumentException;

/**
 * Reads normalized score parameters. Published parameters were validated at
 * publish time; a value that is still unusable here means stored data was
 * altered, so the line is reported as not scorable instead of guessed.
 */
final class Parameters
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function decimal(array $parameters, string $key): BigRational
    {
        $value = $parameters[$key] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Parameter {$key} tidak ada.");
        }

        return Exact::parse($value);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<string>  $allowed
     */
    public static function choice(array $parameters, string $key, array $allowed): string
    {
        $value = $parameters[$key] ?? null;

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Parameter {$key} tidak dikenal.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return non-empty-list<array<string, mixed>>
     */
    public static function rows(array $parameters, string $key): array
    {
        $rows = $parameters[$key] ?? null;

        if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
            throw new InvalidArgumentException("Parameter {$key} tidak ada.");
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException("Parameter {$key} tidak valid.");
            }
        }

        /** @var non-empty-list<array<string, mixed>> $rows */
        return $rows;
    }
}
