<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Domain;

/**
 * Approval steps recorded in the approver snapshot, in the order of the
 * reference flow (ROLE_APPROVAL_MATRIX.md "Approval flow acuan"). The role
 * that acts at each step is given by {@see roleKey()}; who holds that role
 * for a team is configuration, not something the system assumes (DEC-002).
 */
enum ApprovalStage: string
{
    case Verification = 'verification';
    case Review = 'review';
    case Calibration = 'calibration';
    case FinalApproval = 'final_approval';

    /** No account holds the stage role for the subject's unit. */
    public const GAP_NOT_CONFIGURED = 'not_configured';

    /** The only holder is the assessed employee (PRD §4.1: no self-approval). */
    public const GAP_SELF_ONLY = 'self_only';

    public function label(): string
    {
        return match ($this) {
            self::Verification => 'Verifikasi',
            self::Review => 'Review',
            self::Calibration => 'Kalibrasi',
            self::FinalApproval => 'Approval final',
        };
    }

    /**
     * Role value (IdentityAccess\Domain\Role) acting at this stage.
     */
    public function roleKey(): string
    {
        return match ($this) {
            self::Verification => 'team_leader',
            self::Review => 'department_head',
            self::Calibration => 'hr_performance',
            self::FinalApproval => 'management',
        };
    }

    public static function gapLabel(string $reason): string
    {
        return match ($reason) {
            self::GAP_SELF_ONLY => 'Hanya dirinya sendiri (perlu approver pengganti)',
            default => 'Belum dikonfigurasi',
        };
    }
}
