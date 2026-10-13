<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

/**
 * A correction request is decided once: approved (the new actual version and
 * the recalculation exist), rejected, or withdrawn by its requester.
 */
enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu keputusan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
