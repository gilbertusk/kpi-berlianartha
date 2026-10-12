<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain;

/**
 * Allowed values for the measurement fields of a KPI definition
 * (KPI_DEFINITION_TEMPLATE.md §C, §D, §F). Each list is append-only: a value
 * that a published version uses may never be removed or renamed, because the
 * version stores the key. See docs/architecture/KPI_GOVERNANCE.md.
 */
final class Vocabulary
{
    /** @var array<string, string> */
    public const EVALUATION_SUBJECTS = [
        'employee' => 'Employee',
        'team' => 'Team',
        'department' => 'Group/Department',
        'company' => 'Company',
    ];

    /** @var array<string, string> */
    public const VALUE_TYPES = [
        'integer' => 'Integer',
        'decimal' => 'Decimal',
        'percentage' => 'Percentage',
        'currency' => 'Currency',
        'duration' => 'Duration',
        'boolean' => 'Boolean',
        'milestone' => 'Milestone',
    ];

    /** @var array<string, string> */
    public const UNITS = [
        'percent' => 'Persen (%)',
        'count' => 'Jumlah (kejadian)',
        'pieces' => 'Pcs',
        'idr' => 'Rupiah (IDR)',
        'days' => 'Hari',
        'hours' => 'Jam',
        'minutes' => 'Menit',
        'meters' => 'Meter',
        'kilograms' => 'Kilogram',
        'ratio' => 'Rasio',
        'score' => 'Skor',
        'yes_no' => 'Ya/Tidak',
        'stage' => 'Tahap',
    ];

    /** @var array<string, string> */
    public const DIRECTIONS = [
        'higher_is_better' => 'Higher is better',
        'lower_is_better' => 'Lower is better',
        'range' => 'Range',
        'binary' => 'Binary',
        'milestone' => 'Milestone',
    ];

    /** @var array<string, string> */
    public const FREQUENCIES = [
        'daily' => 'Harian',
        'weekly' => 'Mingguan',
        'monthly' => 'Bulanan',
        'quarterly' => 'Kuartalan',
        'annual' => 'Tahunan',
        'event_based' => 'Berbasis kejadian',
    ];

    /** @var array<string, string> */
    public const AGGREGATIONS = [
        'sum' => 'Jumlah (sum)',
        'average' => 'Rata-rata',
        'ratio_of_sums' => 'Rasio dari jumlah (Σ pembilang / Σ penyebut)',
        'last_value' => 'Nilai terakhir periode',
        'minimum' => 'Minimum',
        'maximum' => 'Maksimum',
        'count' => 'Jumlah kejadian (count)',
    ];

    /** @var array<string, string> */
    public const INPUT_METHODS = [
        'manual' => 'Manual',
        'file_import' => 'File import',
        'api' => 'API',
        'database' => 'Database',
        'system_calculation' => 'Perhitungan sistem',
    ];

    /** @var array<string, string> */
    public const EVIDENCE_REQUIREMENTS = [
        'none' => 'Tidak perlu bukti',
        'optional' => 'Bukti opsional',
        'required' => 'Bukti wajib',
    ];

    /**
     * @param  array<string, string>  $list
     */
    public static function has(array $list, ?string $value): bool
    {
        return $value !== null && array_key_exists($value, $list);
    }

    /**
     * @param  array<string, string>  $list
     */
    public static function label(array $list, ?string $value): string
    {
        return $value === null ? '—' : ($list[$value] ?? $value);
    }
}
