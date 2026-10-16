<?php

declare(strict_types=1);

namespace App\Modules\Quality\Domain;

/**
 * The NCR and CAPA lifecycle of PRD §10.3, in order. A stage is left only
 * forward, except that a failed verification returns to corrective action
 * and a closed NCR may be reopened at an investigation stage.
 */
enum NcrStage: string
{
    case Finding = 'TEMUAN';
    case Containment = 'CONTAINMENT';
    case RootCause = 'ROOT_CAUSE';
    case CorrectiveAction = 'CORRECTIVE_ACTION';
    case Verification = 'VERIFICATION';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Finding => 'Temuan',
            self::Containment => 'Containment',
            self::RootCause => 'Root Cause',
            self::CorrectiveAction => 'Corrective Action',
            self::Verification => 'Verification',
            self::Closed => 'Closed',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Finding => self::Containment,
            self::Containment => self::RootCause,
            self::RootCause => self::CorrectiveAction,
            self::CorrectiveAction => self::Verification,
            self::Verification => self::Closed,
            self::Closed => null,
        };
    }

    /**
     * Stages a closed NCR may be reopened at.
     *
     * @return list<self>
     */
    public static function reopenTargets(): array
    {
        return [self::Containment, self::RootCause, self::CorrectiveAction];
    }

    /**
     * What completing the stage records.
     */
    public function contentLabel(): string
    {
        return match ($this) {
            self::Finding => 'Konfirmasi temuan',
            self::Containment => 'Tindakan containment',
            self::RootCause => 'Akar masalah',
            self::CorrectiveAction => 'Correction dan corrective action',
            self::Verification => 'Hasil verifikasi',
            self::Closed => '',
        };
    }
}
