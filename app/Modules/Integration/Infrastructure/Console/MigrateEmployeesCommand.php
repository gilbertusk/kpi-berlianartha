<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Console;

use App\Modules\Integration\Application\EmployeeMigration;
use Illuminate\Console\Command;

final class MigrateEmployeesCommand extends Command
{
    protected $signature = 'integration:migrate-employees {file : CSV in contract migration-employees-v1} {--apply : Commit instead of a dry run}';

    protected $description = 'Dry-run (default) or apply the employee master migration; idempotent by employee number';

    public function handle(EmployeeMigration $migration): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->components->error('File tidak dapat dibaca.');

            return self::FAILURE;
        }

        $run = $migration->run($file, (bool) $this->option('apply'));
        $reconciliation = $run->reconciliation ?? [];

        $this->components->info(sprintf('%s %s: %s', $run->mode, $run->public_id, $run->status));
        $this->components->twoColumnDetail('Baris sumber', (string) ($reconciliation['source_rows'] ?? $run->row_count ?? 0));

        foreach (['created' => 'Dibuat', 'unchanged' => 'Sudah ada dan sama', 'conflicts' => 'Konflik (tidak ditimpa)', 'rejected' => 'Ditolak'] as $key => $label) {
            $this->components->twoColumnDetail($label, (string) ($reconciliation[$key] ?? 0));
        }

        $this->components->twoColumnDetail('Seimbang', ($reconciliation['balanced'] ?? false) ? 'ya' : 'tidak');

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
