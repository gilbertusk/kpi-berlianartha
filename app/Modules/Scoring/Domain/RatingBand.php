<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use Brick\Math\BigDecimal;

/**
 * One rating band of a signed policy: the half-open interval [min, max) of
 * the final score. A null min (first band only) or max (last band only)
 * leaves that side open. A score exactly on a boundary belongs to the band
 * that starts there.
 */
final readonly class RatingBand
{
    public function __construct(
        public string $key,
        public string $label,
        public ?BigDecimal $min,
        public ?BigDecimal $max,
    ) {}

    public function contains(BigDecimal $score): bool
    {
        return ($this->min === null || $score->isGreaterThanOrEqualTo($this->min))
            && ($this->max === null || $score->isLessThan($this->max));
    }

    public function describe(): string
    {
        $min = $this->min === null ? '−∞' : $this->min->strippedOfTrailingZeros()->toString();
        $max = $this->max === null ? '∞' : $this->max->strippedOfTrailingZeros()->toString();

        return "{$this->label} [{$min}, {$max})";
    }

    /**
     * @return array{key: string, label: string, min: string|null, max: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'min' => $this->min?->strippedOfTrailingZeros()->toString(),
            'max' => $this->max?->strippedOfTrailingZeros()->toString(),
        ];
    }
}
