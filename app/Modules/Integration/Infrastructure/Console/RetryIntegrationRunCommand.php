<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Console;

use App\Modules\Integration\Application\InboundRunner;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Console\Command;

final class RetryIntegrationRunCommand extends Command
{
    protected $signature = 'integration:retry {run : Public id of a failed or dead-lettered run}';

    protected $description = 'Manually retry one failed or dead-lettered inbound run';

    public function handle(InboundRunner $runner): int
    {
        $run = IntegrationRun::query()->where('public_id', (string) $this->argument('run'))->first();

        if ($run === null) {
            $this->components->error('Run tidak ditemukan.');

            return self::FAILURE;
        }

        try {
            $run = $runner->retry($run);
        } catch (BusinessRuleViolation $violation) {
            $this->components->error($violation->safeMessage());

            return self::FAILURE;
        }

        $this->components->info("{$run->connection} {$run->file_name}: {$run->status}");

        return self::SUCCESS;
    }
}
