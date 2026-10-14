<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Console;

use App\Modules\Reporting\Application\ExportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class PurgeExpiredExportsCommand extends Command
{
    protected $signature = 'reports:purge-expired-exports';

    protected $description = 'Fail exports stuck in running, then delete export files past their retention.';

    public function handle(ExportService $exports): int
    {
        $now = CarbonImmutable::now();
        $this->info('Stale running exports failed: '.$exports->failStale($now).'.');
        $this->info('Expired exports purged: '.$exports->purgeExpired($now).'.');

        return self::SUCCESS;
    }
}
