<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Infrastructure\Scanning;

use App\Modules\ActualEvidence\Application\MalwareScanner;
use App\Modules\ActualEvidence\Domain\ScanStatus;

/**
 * Local and test environments only: files are usable without a scan and
 * are labelled as unscanned, never as clean. The service provider refuses
 * to select this adapter in any other environment.
 */
final class LocalUnscannedScanner implements MalwareScanner
{
    public function name(): string
    {
        return 'local-unscanned';
    }

    public function scan($stream): ScanStatus
    {
        return ScanStatus::AcceptedUnscanned;
    }
}
