<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Infrastructure\Scanning;

use App\Modules\ActualEvidence\Application\MalwareScanner;
use App\Modules\ActualEvidence\Domain\ScanStatus;

/**
 * Default adapter while no antivirus is integrated: every file stays in
 * quarantine and cannot be opened. Nothing is ever reported as clean.
 */
final class UnavailableScanner implements MalwareScanner
{
    public function name(): string
    {
        return 'none';
    }

    public function scan($stream): ScanStatus
    {
        return ScanStatus::Quarantined;
    }
}
