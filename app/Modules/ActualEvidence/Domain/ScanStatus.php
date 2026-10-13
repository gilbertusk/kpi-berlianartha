<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Domain;

/**
 * Malware scan state of an evidence file. A file is served only when it is
 * CLEAN, or ACCEPTED_UNSCANNED in a local or test environment where no
 * scanner exists. QUARANTINED and INFECTED files are never served.
 */
enum ScanStatus: string
{
    case Quarantined = 'quarantined';
    case Clean = 'clean';
    case Infected = 'infected';
    case AcceptedUnscanned = 'accepted_unscanned';

    public function label(): string
    {
        return match ($this) {
            self::Quarantined => 'Karantina (belum dipindai)',
            self::Clean => 'Bersih',
            self::Infected => 'Terinfeksi',
            self::AcceptedUnscanned => 'Belum dipindai (lingkungan lokal)',
        };
    }

    public function isServable(): bool
    {
        return $this === self::Clean || $this === self::AcceptedUnscanned;
    }
}
