<?php

declare(strict_types=1);

namespace App\Modules\Target\Domain;

use InvalidArgumentException;

/**
 * Validates a target value against the KPI definition version it belongs
 * to: unit, number of decimals, and the valid input range. Nothing here is a
 * business default; a rule applies only when the definition states it.
 *
 * Values are handled as integers scaled by 10^4 and stored as numeric(18,4);
 * floats are never used (ADR-0008).
 */
final readonly class TargetValueRules
{
    public const SCALE = 4;

    private const UNIT = 10_000;

    /** Value types whose values are whole numbers by nature. */
    private const WHOLE_NUMBER_TYPES = ['integer', 'boolean', 'milestone'];

    private function __construct(
        public ?string $unit,
        public ?string $valueType,
        public int $decimalPlaces,
        private ?int $minimum,
        private ?int $maximum,
        private ?int $stageCount,
        private string $noun = 'target',
    ) {}

    /**
     * The same rules for another kind of value (actuals), so messages name it.
     */
    public function named(string $noun): self
    {
        return new self($this->unit, $this->valueType, $this->decimalPlaces, $this->minimum, $this->maximum, $this->stageCount, $noun);
    }

    /**
     * @param  array<string, mixed>|null  $scoreParameters
     */
    public static function for(?string $unit, ?string $valueType, ?int $decimalPlaces, ?string $minimum, ?string $maximum, ?array $scoreParameters): self
    {
        $places = in_array($valueType, self::WHOLE_NUMBER_TYPES, true) ? 0 : self::SCALE;

        if ($decimalPlaces !== null && $decimalPlaces >= 0 && $decimalPlaces < $places) {
            $places = $decimalPlaces;
        }

        $stages = $scoreParameters['stages'] ?? null;

        return new self(
            unit: $unit,
            valueType: $valueType,
            decimalPlaces: $places,
            minimum: $minimum === null ? null : self::scale($minimum),
            maximum: $maximum === null ? null : self::scale($maximum),
            stageCount: $valueType === 'milestone' && is_array($stages) && $stages !== [] ? count($stages) : null,
        );
    }

    /**
     * Returns the value as a canonical four-decimal string for storage.
     *
     * @throws InvalidArgumentException with a message that is safe to show
     */
    public function parse(string $raw, string $unit): string
    {
        if ($this->unit === null) {
            throw new InvalidArgumentException("KPI ini belum memiliki satuan, sehingga {$this->noun} tidak dapat diisi.");
        }

        if (trim($unit) !== $this->unit) {
            throw new InvalidArgumentException("Satuan {$this->noun} ({$unit}) tidak sesuai dengan satuan KPI ({$this->unit}).");
        }

        $raw = trim($raw);

        if (preg_match('/^(-?)(\d{1,12})(?:\.(\d+))?$/', $raw, $matches) !== 1) {
            throw new InvalidArgumentException("Nilai {$this->noun} harus berupa angka dengan titik sebagai pemisah desimal, tanpa pemisah ribuan, dan paling banyak 12 digit sebelum desimal.");
        }

        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > $this->decimalPlaces) {
            throw new InvalidArgumentException($this->decimalPlaces === 0
                ? 'KPI ini hanya menerima bilangan bulat.'
                : "KPI ini menerima paling banyak {$this->decimalPlaces} angka di belakang koma.");
        }

        $scaled = (int) $matches[2] * self::UNIT + (int) str_pad($fraction, self::SCALE, '0');
        $scaled = $matches[1] === '-' ? -$scaled : $scaled;

        if ($this->valueType === 'boolean' && ! in_array($scaled, [0, self::UNIT], true)) {
            throw new InvalidArgumentException('KPI Ya/Tidak hanya menerima 1 (Ya) atau 0 (Tidak).');
        }

        if ($this->stageCount !== null && ($scaled < self::UNIT || $scaled > $this->stageCount * self::UNIT)) {
            throw new InvalidArgumentException("Nilai {$this->noun} milestone harus berupa nomor tahap 1 sampai {$this->stageCount}.");
        }

        if ($this->minimum !== null && $scaled < $this->minimum) {
            throw new InvalidArgumentException("Nilai {$this->noun} lebih kecil dari nilai input minimum KPI (".self::format($this->minimum).').');
        }

        if ($this->maximum !== null && $scaled > $this->maximum) {
            throw new InvalidArgumentException("Nilai {$this->noun} lebih besar dari nilai input maksimum KPI (".self::format($this->maximum).').');
        }

        return sprintf('%s%d.%04d', $scaled < 0 ? '-' : '', intdiv(abs($scaled), self::UNIT), abs($scaled) % self::UNIT);
    }

    /**
     * Stored value without trailing zeros, for display and comparison.
     */
    public static function display(?string $stored): string
    {
        if ($stored === null) {
            return '—';
        }

        $scaled = self::scale($stored);

        return $scaled === null ? $stored : self::format($scaled);
    }

    private static function scale(string $value): ?int
    {
        if (preg_match('/^(-?)(\d{1,14})(?:\.(\d{1,4}))?$/', trim($value), $matches) !== 1) {
            return null;
        }

        $scaled = (int) $matches[2] * self::UNIT + (int) str_pad($matches[3] ?? '', self::SCALE, '0');

        return $matches[1] === '-' ? -$scaled : $scaled;
    }

    private static function format(int $scaled): string
    {
        $absolute = abs($scaled);
        $fraction = rtrim(str_pad((string) ($absolute % self::UNIT), self::SCALE, '0', STR_PAD_LEFT), '0');

        return ($scaled < 0 ? '-' : '').intdiv($absolute, self::UNIT).($fraction === '' ? '' : ".{$fraction}");
    }
}
