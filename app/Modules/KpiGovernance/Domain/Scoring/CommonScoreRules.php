<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain\Scoring;

/**
 * Rules every strategy must state explicitly. DEC-004 (rounding, missing
 * data, cap/floor) is still OPEN, so the system offers choices but never
 * defaults one: each KPI version records the signed-off choice.
 */
final class CommonScoreRules
{
    /** @var array<string, string> */
    public const MISSING_DATA_RULES = [
        'mark_not_applicable' => 'Tandai N/A (tidak dinilai)',
        'score_zero' => 'Skor nol',
        'block_until_provided' => 'Tahan finalisasi sampai data tersedia',
    ];

    /** @var array<string, string> */
    public const ROUNDING_MODES = [
        'half_up' => 'Half up',
        'half_even' => 'Half even (banker)',
        'down' => 'Truncate (down)',
    ];

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    public static function validate(array $parameters): array
    {
        $errors = [];

        if (! is_string($parameters['missing_data_rule'] ?? null) || ! array_key_exists($parameters['missing_data_rule'], self::MISSING_DATA_RULES)) {
            $errors[] = 'Aturan missing data wajib dipilih.';
        }

        if (! is_string($parameters['rounding_mode'] ?? null) || ! array_key_exists($parameters['rounding_mode'], self::ROUNDING_MODES)) {
            $errors[] = 'Mode pembulatan wajib dipilih.';
        }

        $scale = $parameters['rounding_scale'] ?? null;

        if (! (is_int($scale) || (is_string($scale) && ctype_digit($scale))) || (int) $scale > ScaledDecimal::SCALE) {
            $errors[] = 'Jumlah desimal pembulatan harus 0 sampai 4.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function normalize(array $parameters): array
    {
        return [
            'missing_data_rule' => (string) $parameters['missing_data_rule'],
            'rounding_mode' => (string) $parameters['rounding_mode'],
            'rounding_scale' => (int) $parameters['rounding_scale'],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function describe(array $parameters): string
    {
        return sprintf(
            'missing data: %s; pembulatan: %s, %d desimal',
            self::MISSING_DATA_RULES[$parameters['missing_data_rule'] ?? ''] ?? '—',
            self::ROUNDING_MODES[$parameters['rounding_mode'] ?? ''] ?? '—',
            (int) ($parameters['rounding_scale'] ?? 0),
        );
    }

    /**
     * Validates score_floor / score_cap (non-negative decimals, floor ≤ cap).
     *
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    public static function validateCapFloor(array $parameters): array
    {
        $floor = ScaledDecimal::parse($parameters['score_floor'] ?? null);
        $cap = ScaledDecimal::parse($parameters['score_cap'] ?? null);

        if ($floor === null || $cap === null) {
            return ['Batas bawah dan batas atas skor wajib diisi sebagai angka desimal non-negatif.'];
        }

        return $floor > $cap ? ['Batas bawah skor tidak boleh lebih besar dari batas atas.'] : [];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $allowed
     */
    public static function requireChoice(array $parameters, string $key, array $allowed, string $message): ?string
    {
        $value = $parameters[$key] ?? null;

        return is_string($value) && array_key_exists($value, $allowed) ? null : $message;
    }
}
