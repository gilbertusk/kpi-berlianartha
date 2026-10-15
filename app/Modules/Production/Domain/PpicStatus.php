<?php

declare(strict_types=1);

namespace App\Modules\Production\Domain;

/**
 * The PPIC board statuses of PRD §10.1, in board order. The PRD fixes the
 * statuses but no allowed transitions; any change is recorded with its time,
 * actor, and work order, and a move back or a move of an overdue work order
 * needs a reason.
 */
enum PpicStatus: string
{
    case WaitingMaterial = 'WAITING_MATERIAL';
    case WaitingDesign = 'WAITING_DESIGN';
    case ReadyForProduction = 'READY_FOR_PRODUCTION';
    case InProduction = 'IN_PRODUCTION';
    case Qc = 'QC';
    case Packing = 'PACKING';
    case ReadyToDeliver = 'READY_TO_DELIVER';
    case Completed = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::WaitingMaterial => 'Waiting Material',
            self::WaitingDesign => 'Waiting Design',
            self::ReadyForProduction => 'Ready for Production',
            self::InProduction => 'In Production',
            self::Qc => 'QC',
            self::Packing => 'Packing',
            self::ReadyToDeliver => 'Ready to Deliver',
            self::Completed => 'Completed',
        };
    }

    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    public function isBefore(self $other): bool
    {
        return $this->position() < $other->position();
    }
}
