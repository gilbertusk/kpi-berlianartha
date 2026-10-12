<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence\Casts;

use App\Modules\Shared\Domain\EffectivePeriod;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Keeps business dates as plain YYYY-MM-DD strings.
 *
 * The default "date" cast stores "Y-m-d H:i:s" on SQLite, which breaks the
 * half-open string comparisons used for effective periods.
 *
 * @implements CastsAttributes<string|null, mixed>
 */
final class BusinessDate implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a YYYY-MM-DD business date string.");
        }

        // Reuses the strict calendar validation of the domain value object.
        new EffectivePeriod($value);

        return $value;
    }
}
