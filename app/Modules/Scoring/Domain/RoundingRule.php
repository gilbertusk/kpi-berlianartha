<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * One signed rounding step: a mode and a number of decimals. The modes are
 * the ones a KPI definition offers (CommonScoreRules::ROUNDING_MODES); the
 * system never picks one. Rounding is applied once, to the exact value.
 */
final readonly class RoundingRule
{
    /** @var array<string, string> */
    public const MODES = [
        'half_up' => 'Half up',
        'half_even' => 'Half even (banker)',
        'down' => 'Truncate (down)',
    ];

    /** Scores and contributions are stored as numeric(18,4). */
    public const MAX_SCALE = 4;

    /**
     * @param  int<0, 4>  $scale
     */
    private function __construct(public string $mode, public int $scale) {}

    /**
     * @throws InvalidArgumentException when the mode or scale is not allowed
     */
    public static function of(mixed $mode, mixed $scale): self
    {
        if (! is_string($mode) || ! array_key_exists($mode, self::MODES)) {
            throw new InvalidArgumentException('Mode pembulatan tidak dikenal.');
        }

        if (is_string($scale) && preg_match('/^\d$/', $scale) === 1) {
            $scale = (int) $scale;
        }

        if (! is_int($scale) || $scale < 0 || $scale > self::MAX_SCALE) {
            throw new InvalidArgumentException('Jumlah desimal pembulatan harus 0 sampai '.self::MAX_SCALE.'.');
        }

        return new self($mode, $scale);
    }

    public function apply(BigRational $value): BigDecimal
    {
        return $value->toScale($this->scale, match ($this->mode) {
            'half_up' => RoundingMode::HalfUp,
            'half_even' => RoundingMode::HalfEven,
            default => RoundingMode::Down,
        });
    }

    public function describe(): string
    {
        return self::MODES[$this->mode].", {$this->scale} desimal";
    }

    /**
     * @return array{mode: string, scale: int}
     */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'scale' => $this->scale];
    }
}
