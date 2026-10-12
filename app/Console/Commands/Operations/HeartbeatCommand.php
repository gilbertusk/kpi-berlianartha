<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Support\Operations\Heartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:heartbeat {process : scheduler|worker} {--check : Verify the heartbeat instead of recording it} {--max-age=150 : Maximum heartbeat age in seconds for --check}')]
#[Description('Record or verify the liveness heartbeat of a long-running process')]
final class HeartbeatCommand extends Command
{
    public function handle(Heartbeat $heartbeat): int
    {
        $process = (string) $this->argument('process');

        if (! in_array($process, Heartbeat::PROCESSES, true)) {
            $this->error("Proses tidak dikenal: {$process}.");

            return self::FAILURE;
        }

        if (! $this->option('check')) {
            $heartbeat->beat($process);

            return self::SUCCESS;
        }

        $maxAge = (int) $this->option('max-age');

        if (! $heartbeat->isFresh($process, $maxAge)) {
            $this->error("Heartbeat {$process} tidak ada atau lebih lama dari {$maxAge} detik.");

            return self::FAILURE;
        }

        $this->info("Heartbeat {$process} sehat.");

        return self::SUCCESS;
    }
}
