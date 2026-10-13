<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Domain;

/**
 * Stages of the assessment flow (PRD §6.1) that can carry a deadline. The
 * dates themselves are entered per period: SLA values are not decided
 * (DEC-009), so the system never proposes a default.
 */
enum DeadlineStage: string
{
    case TargetSetting = 'target_setting';
    case ActualSubmission = 'actual_submission';
    case Verification = 'verification';
    case Review = 'review';
    case Calibration = 'calibration';
    case FinalApproval = 'final_approval';

    public function label(): string
    {
        return match ($this) {
            self::TargetSetting => 'Penetapan target',
            self::ActualSubmission => 'Pengisian aktual dan bukti',
            self::Verification => 'Verifikasi Team Leader',
            self::Review => 'Review Manager/Head',
            self::Calibration => 'Kelengkapan dan kalibrasi HR',
            self::FinalApproval => 'Approval final Management',
        };
    }

    /**
     * Problems in a set of deadlines: stages follow one another, so a later
     * stage may not close before an earlier one.
     *
     * @param  array<string, string>  $dueOnByStage  stage value => YYYY-MM-DD
     * @return list<string>
     */
    public static function orderProblems(array $dueOnByStage): array
    {
        $problems = [];
        $previous = null;

        foreach (self::cases() as $stage) {
            $dueOn = $dueOnByStage[$stage->value] ?? null;

            if ($dueOn === null) {
                continue;
            }

            if ($previous !== null && $dueOn < $dueOnByStage[$previous->value]) {
                $problems[] = "Deadline {$stage->label()} tidak boleh sebelum deadline {$previous->label()}.";
            }

            $previous = $stage;
        }

        return $problems;
    }
}
