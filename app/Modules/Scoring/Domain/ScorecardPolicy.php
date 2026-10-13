<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * The scorecard-level rules of DEC-004 that a KPI definition does not carry:
 * late data, the weight of N/A KPIs, rounding of contributions and of the
 * final score, and the rating bands. A policy exists only as a signed,
 * versioned record; nothing here has a default value. While DEC-004 is open
 * there is no policy, and official scoring stays blocked.
 *
 * A published policy version never changes meaning: different rules need a
 * new version number, because stored calculations reference it.
 */
final readonly class ScorecardPolicy
{
    /** @var array<string, string> */
    public const LATE_DATA_RULES = [
        'include' => 'Aktual terlambat tetap dihitung dan ditandai',
        'exclude' => 'Aktual terlambat tidak dihitung',
    ];

    /** @var array<string, string> */
    public const NOT_APPLICABLE_WEIGHT_RULES = [
        'redistribute_proportionally' => 'Bobot KPI N/A dibagi proporsional ke KPI yang dinilai',
        'keep_full_denominator' => 'Pembagi tetap 100%; KPI N/A tidak menyumbang skor',
        'block_until_resolved' => 'Scorecard dengan KPI N/A ditahan sampai diputuskan',
    ];

    private const FIELDS = [
        'key', 'version', 'signoff_reference', 'effective_from', 'effective_until', 'late_data_rule',
        'not_applicable_weight_rule', 'contribution_rounding', 'final_rounding', 'rating_bands',
    ];

    private const OPTIONAL_FIELDS = ['effective_until'];

    private const MAX_BANDS = 20;

    private const BOUNDARY = '/^-?\d{1,12}(?:\.\d{1,4})?$/';

    /**
     * @param  list<RatingBand>  $ratingBands  ascending and contiguous
     */
    private function __construct(
        public string $key,
        public int $version,
        public string $signoffReference,
        public string $effectiveFrom,
        public ?string $effectiveUntil,
        public string $lateDataRule,
        public string $notApplicableWeightRule,
        public RoundingRule $contributionRounding,
        public RoundingRule $finalRounding,
        public array $ratingBands,
    ) {}

    /**
     * @param  array<mixed>  $config
     *
     * @throws InvalidArgumentException listing every problem
     */
    public static function fromArray(array $config): self
    {
        $errors = [];

        foreach (array_diff(array_keys($config), self::FIELDS) as $unknown) {
            $errors[] = "Field tidak dikenal: {$unknown}.";
        }

        foreach (array_diff(self::FIELDS, self::OPTIONAL_FIELDS, array_keys($config)) as $missing) {
            $errors[] = "Field wajib tidak ada: {$missing}.";
        }

        $key = $config['key'] ?? null;

        if (! is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{1,59}$/', $key) !== 1) {
            $errors[] = 'key harus 2–60 karakter huruf kecil, angka, garis bawah, atau tanda hubung.';
        }

        $version = $config['version'] ?? null;

        if (! is_int($version) || $version < 1) {
            $errors[] = 'version harus bilangan bulat ≥ 1.';
        }

        $signoff = is_string($config['signoff_reference'] ?? null) ? trim($config['signoff_reference']) : '';

        if ($signoff === '' || mb_strlen($signoff) > 255) {
            $errors[] = 'signoff_reference wajib diisi (maks. 255 karakter).';
        }

        $from = $config['effective_from'] ?? null;
        $until = $config['effective_until'] ?? null;

        if (! self::isDate($from)) {
            $errors[] = 'effective_from harus tanggal YYYY-MM-DD.';
        }

        if ($until !== null && (! self::isDate($until) || (self::isDate($from) && $until <= $from))) {
            $errors[] = 'effective_until harus tanggal YYYY-MM-DD setelah effective_from.';
        }

        if (! is_string($config['late_data_rule'] ?? null) || ! array_key_exists($config['late_data_rule'], self::LATE_DATA_RULES)) {
            $errors[] = 'late_data_rule tidak dikenal.';
        }

        if (! is_string($config['not_applicable_weight_rule'] ?? null) || ! array_key_exists($config['not_applicable_weight_rule'], self::NOT_APPLICABLE_WEIGHT_RULES)) {
            $errors[] = 'not_applicable_weight_rule tidak dikenal.';
        }

        $contributionRounding = self::rounding($config, 'contribution_rounding', $errors);
        $finalRounding = self::rounding($config, 'final_rounding', $errors);
        $bands = self::bands($config['rating_bands'] ?? null, $errors);

        if ($errors !== [] || $contributionRounding === null || $finalRounding === null) {
            throw new InvalidArgumentException('Policy scorecard tidak valid: '.implode(' ', $errors));
        }

        /** @var string $key */
        /** @var int $version */
        /** @var string $from */
        /** @var string|null $until */
        return new self(
            $key,
            $version,
            $signoff,
            $from,
            $until,
            (string) $config['late_data_rule'],
            (string) $config['not_applicable_weight_rule'],
            $contributionRounding,
            $finalRounding,
            $bands,
        );
    }

    public function reference(): string
    {
        return "{$this->key}@{$this->version}";
    }

    /**
     * Half-open: effective_from inclusive, effective_until exclusive.
     */
    public function isInForceOn(string $businessDate): bool
    {
        return $businessDate >= $this->effectiveFrom && ($this->effectiveUntil === null || $businessDate < $this->effectiveUntil);
    }

    public function overlaps(self $other): bool
    {
        return ($other->effectiveUntil === null || $this->effectiveFrom < $other->effectiveUntil)
            && ($this->effectiveUntil === null || $other->effectiveFrom < $this->effectiveUntil);
    }

    public function ratingFor(BigDecimal $score): ?RatingBand
    {
        foreach ($this->ratingBands as $band) {
            if ($band->contains($score)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * Fingerprint of the rules only. Effective dates are lifecycle data: a
     * version is closed by giving it an effective_until when the next one
     * starts, which does not change what it computes.
     */
    public function hash(): string
    {
        return Canonical::hash($this->rules());
    }

    /**
     * Everything that decides a result: identity, sign-off, and rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->toArray();
        unset($rules['effective_from'], $rules['effective_until']);

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'signoff_reference' => $this->signoffReference,
            'effective_from' => $this->effectiveFrom,
            'effective_until' => $this->effectiveUntil,
            'late_data_rule' => $this->lateDataRule,
            'not_applicable_weight_rule' => $this->notApplicableWeightRule,
            'contribution_rounding' => $this->contributionRounding->toArray(),
            'final_rounding' => $this->finalRounding->toArray(),
            'rating_bands' => array_map(static fn (RatingBand $band): array => $band->toArray(), $this->ratingBands),
        ];
    }

    private static function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * @param  array<mixed>  $config
     * @param  list<string>  $errors
     */
    private static function rounding(array $config, string $field, array &$errors): ?RoundingRule
    {
        $rule = $config[$field] ?? null;

        if (! is_array($rule) || array_keys($rule) !== ['mode', 'scale']) {
            $errors[] = "{$field}: harus berisi tepat mode dan scale.";

            return null;
        }

        try {
            return RoundingRule::of($rule['mode'], $rule['scale']);
        } catch (InvalidArgumentException $exception) {
            $errors[] = "{$field}: {$exception->getMessage()}";

            return null;
        }
    }

    /**
     * @param  list<string>  $errors
     * @return list<RatingBand>
     */
    private static function bands(mixed $rows, array &$errors): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || $rows === [] || count($rows) > self::MAX_BANDS) {
            $errors[] = 'rating_bands wajib berisi 1 sampai '.self::MAX_BANDS.' band.';

            return [];
        }

        $bands = [];
        $keys = [];
        $last = count($rows) - 1;

        foreach ($rows as $index => $row) {
            $number = $index + 1;

            if (! is_array($row) || array_diff(array_keys($row), ['key', 'label', 'min', 'max']) !== []) {
                $errors[] = "Band {$number}: hanya key, label, min, dan max yang diizinkan.";

                continue;
            }

            $key = $row['key'] ?? null;
            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';

            if (! is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $key) !== 1 || in_array($key, $keys, true)) {
                $errors[] = "Band {$number}: key wajib unik (huruf kecil, angka, _ atau -).";
            }

            if ($label === '' || mb_strlen($label) > 80) {
                $errors[] = "Band {$number}: label wajib diisi (maks. 80 karakter).";
            }

            $min = self::boundary($row['min'] ?? null);
            $max = self::boundary($row['max'] ?? null);

            if ($min === false || $max === false) {
                $errors[] = "Band {$number}: min dan max harus angka desimal (maks. 4 desimal).";

                continue;
            }

            if (($min === null && $index !== 0) || ($max === null && $index !== $last)) {
                $errors[] = "Band {$number}: hanya band pertama yang boleh tanpa min dan hanya band terakhir yang boleh tanpa max.";
            }

            if ($min !== null && $max !== null && ! $min->isLessThan($max)) {
                $errors[] = "Band {$number}: min harus lebih kecil dari max.";
            }

            $previous = $bands[count($bands) - 1] ?? null;

            if ($previous !== null && ($previous->max === null || $min === null || ! $previous->max->isEqualTo($min))) {
                $errors[] = "Band {$number}: min harus sama dengan max band sebelumnya (tanpa celah atau tumpang tindih).";
            }

            $keys[] = (string) $key;
            $bands[] = new RatingBand((string) $key, $label, $min, $max);
        }

        return $bands;
    }

    private static function boundary(mixed $value): BigDecimal|false|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match(self::BOUNDARY, trim($value)) !== 1) {
            return false;
        }

        return BigDecimal::of(trim($value));
    }
}
