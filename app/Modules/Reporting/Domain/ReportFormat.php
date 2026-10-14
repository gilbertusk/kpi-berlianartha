<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

enum ReportFormat: string
{
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Xlsx => 'Excel (.xlsx)',
            self::Pdf => 'PDF',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }
}
