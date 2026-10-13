<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * What a notification is about (PRD §12). Preferences are kept per category.
 */
enum NotificationCategory: string
{
    case ApprovalTask = 'approval_task';
    case ScorecardReturned = 'scorecard_returned';
    case Correction = 'correction';
    case ActualRejected = 'actual_rejected';
    case CorrectiveAction = 'corrective_action';
    case Reminder = 'reminder';
    case Escalation = 'escalation';

    public function label(): string
    {
        return match ($this) {
            self::ApprovalTask => 'Tugas approval',
            self::ScorecardReturned => 'Scorecard dikembalikan',
            self::Correction => 'Koreksi',
            self::ActualRejected => 'Aktual ditolak',
            self::CorrectiveAction => 'Corrective action',
            self::Reminder => 'Pengingat tenggat',
            self::Escalation => 'Eskalasi',
        };
    }
}
