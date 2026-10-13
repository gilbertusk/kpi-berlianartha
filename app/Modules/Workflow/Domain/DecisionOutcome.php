<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

/**
 * What one entry of the review history records.
 */
enum DecisionOutcome: string
{
    case Approved = 'approved';
    case Returned = 'returned';

    /** The stage had no approver and a signed bypass for the team was in force. */
    case Bypassed = 'bypassed';
    case Resubmitted = 'resubmitted';

    /** An approved correction replaced the calculation under review. */
    case Recalculated = 'recalculated';

    /** History stages that are not approval stages. */
    public const STAGE_RESUBMISSION = 'resubmission';

    public const STAGE_CORRECTION = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Disetujui',
            self::Returned => 'Dikembalikan',
            self::Bypassed => 'Dilewati (bypass disetujui)',
            self::Resubmitted => 'Diajukan ulang',
            self::Recalculated => 'Dihitung ulang karena koreksi',
        };
    }

    public static function stageLabel(string $stage): string
    {
        return match ($stage) {
            'review' => 'Review',
            'calibration' => 'Kalibrasi',
            'final_approval' => 'Approval final',
            self::STAGE_RESUBMISSION => 'Pengajuan ulang',
            self::STAGE_CORRECTION => 'Koreksi',
            default => $stage,
        };
    }
}
