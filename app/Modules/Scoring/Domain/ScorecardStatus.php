<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

/**
 * Complete: every KPI is scored or excluded under a signed rule, and the
 * final score and rating exist. Incomplete: at least one blocker, listed
 * with the calculation; no rating is given.
 */
enum ScorecardStatus: string
{
    case Complete = 'complete';
    case Incomplete = 'incomplete';

    /** @var array<string, string> blocker code => explanation */
    public const BLOCKERS = [
        'no_lines' => 'Scorecard tidak memiliki KPI yang berlaku untuk subjek ini.',
        'kpi_blocked' => 'Ada KPI yang belum dapat dinilai (lihat status per KPI).',
        'weight_not_100' => 'Total bobot KPI yang berlaku untuk subjek ini bukan 100%. Aturan normalisasi bobot belum diputuskan (DEC-003/DEC-004).',
        'not_applicable_blocked' => 'Ada KPI N/A dan aturan scorecard menahan perhitungan sampai diputuskan.',
        'no_scored_kpi' => 'Tidak ada KPI yang dinilai, sehingga skor akhir tidak dapat dihitung.',
        'rating_out_of_range' => 'Skor akhir berada di luar seluruh rating band aturan scorecard.',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Lengkap',
            self::Incomplete => 'Belum lengkap',
        };
    }

    public static function blockerLabel(string $code): string
    {
        return self::BLOCKERS[$code] ?? $code;
    }
}
