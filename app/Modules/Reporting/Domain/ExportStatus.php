<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

enum ExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Dalam antrean',
            self::Running => 'Sedang dibuat',
            self::Completed => 'Selesai',
            self::Failed => 'Gagal',
            self::Expired => 'Kedaluwarsa',
        };
    }

    public function isInFlight(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
