<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Console;

use App\Modules\Integration\Application\InboundRunner;
use Illuminate\Console\Command;

final class RunIntegrationCommand extends Command
{
    protected $signature = 'integration:run {connection? : Only this connection}';

    protected $description = 'Process waiting inbound files of runnable integration connections (counts only, never row data or credentials)';

    public function handle(InboundRunner $runner): int
    {
        $connection = $this->argument('connection');
        $result = $runner->run(is_string($connection) ? $connection : null);

        foreach ($result['skipped'] as $key => $reason) {
            $this->components->warn("{$key}: {$reason}");
        }

        foreach ($result['runs'] as $run) {
            $this->components->info("{$run->connection} {$run->file_name}: {$run->status} (baris {$run->row_count}, ditolak {$run->rejected_count}, duplikat {$run->duplicate_count})");
        }

        return self::SUCCESS;
    }
}
