<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Modules\ActualEvidence\Application\ImportSheet;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Migration of the employee master from a legacy export (contract
 * `migration-employees-v1`). A dry run does everything inside a transaction
 * and rolls it back; an apply commits. Both are idempotent by employee
 * number: an employee already present with the same name and home team is
 * left alone, one that differs is a conflict for the data owner, never an
 * overwrite. KPI history, targets, actuals, and approvals are not migrated:
 * no approved legacy source exists, and approval history is never made up.
 */
final readonly class EmployeeMigration
{
    public const CONNECTION = 'hris-employees';

    public const COLUMNS = ['employee_number', 'full_name', 'home_team_code', 'position_title', 'effective_from'];

    public const MAX_ROWS = 20000;

    public function __construct(
        private EmployeeService $employees,
        private OrganizationDirectory $directory,
        private ExceptionRegister $exceptions,
        private BusinessTime $time,
    ) {}

    /**
     * @param  array<string, string>  $columnMap  legacy column => contract column
     */
    public function run(string $path, bool $apply, array $columnMap = []): IntegrationRun
    {
        $run = IntegrationRun::query()->create([
            'connection' => self::CONNECTION,
            'mode' => $apply ? IntegrationRun::MIGRATION_APPLY : IntegrationRun::MIGRATION_DRY_RUN,
            'file_name' => mb_substr(basename($path), 0, 160),
            'content_hash' => (string) hash_file('sha256', $path),
            'status' => IntegrationRun::RECEIVED,
            'attempts' => 1,
            'started_at' => CarbonImmutable::now(),
        ]);
        $mapped = TempFile::create('kpi-migration-');

        try {
            CsvHeaderMapper::map($path, $mapped, $columnMap);
            $rows = ImportSheet::read($mapped, 'csv', self::MAX_ROWS, self::COLUMNS, ['employee_number', 'full_name', 'home_team_code', 'effective_from']);
        } catch (InvalidArgumentException $invalid) {
            $run->forceFill(['status' => IntegrationRun::QUARANTINED, 'error_code' => 'invalid_file', 'error_message' => mb_substr($invalid->getMessage(), 0, 500), 'finished_at' => CarbonImmutable::now()])->save();
            $this->exceptions->record($run, self::CONNECTION, 'invalid_file', $invalid->getMessage());

            return $run;
        } finally {
            @unlink($mapped);
        }

        $actor = Actor::system('migration', "employees:{$run->public_id}");
        $result = ['created' => [], 'unchanged' => [], 'conflicts' => [], 'rejected' => []];
        $perTeam = [];

        DB::beginTransaction();

        try {
            $seen = [];
            $teams = [];

            foreach ($rows as $row) {
                $outcome = $this->row($actor, $row['cells'], $row['line'], $seen, $teams);
                $result[$outcome['result']][] = $outcome['message'];

                if (in_array($outcome['result'], ['created', 'unchanged'], true)) {
                    $perTeam[$outcome['team']] = ($perTeam[$outcome['team']] ?? 0) + 1;
                }
            }

            $reconciliation = $this->reconcile($rows, $result, $perTeam);
            $apply ? DB::commit() : DB::rollBack();
        } catch (Throwable $failure) {
            DB::rollBack();
            $run->forceFill(['status' => IntegrationRun::FAILED, 'error_code' => 'transient_failure', 'error_message' => 'Kegagalan teknis ('.class_basename($failure).'); tidak ada yang tersimpan.', 'finished_at' => CarbonImmutable::now()])->save();

            return $run;
        }

        foreach ($result['conflicts'] as $message) {
            $this->exceptions->record($run, self::CONNECTION, 'migration_conflict', $message);
        }

        foreach ($result['rejected'] as $message) {
            $this->exceptions->record($run, self::CONNECTION, 'rows_rejected', $message);
        }

        $run->forceFill([
            'status' => $apply ? IntegrationRun::APPLIED : IntegrationRun::DRY_RUN,
            'row_count' => count($rows),
            'accepted_count' => count($result['created']) + count($result['unchanged']),
            'rejected_count' => count($result['rejected']) + count($result['conflicts']),
            'duplicate_count' => count($result['unchanged']),
            'reconciliation' => $reconciliation,
            'reconciled' => $reconciliation['balanced'] && $result['rejected'] === [] && $result['conflicts'] === [],
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        return $run;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, int>  $seen
     * @param  array<string, array<string, int>>  $teams  effective date => team code => unit id
     * @return array{result: 'created'|'unchanged'|'conflicts'|'rejected', message: string, team: string}
     */
    private function row(Actor $actor, array $cells, int $line, array &$seen, array &$teams): array
    {
        $cells = array_map(static fn (string $value): string => trim($value), $cells);
        $number = strtoupper($cells['employee_number'] ?? '');
        $team = strtoupper($cells['home_team_code'] ?? '');
        $from = $cells['effective_from'] ?? '';
        $reject = static fn (string $why): array => ['result' => 'rejected', 'message' => "Baris {$line}: {$why}.", 'team' => $team];

        if (isset($seen[$number])) {
            return $reject("nomor induk duplikat dari baris {$seen[$number]}");
        }

        $seen[$number] = $line;

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $from);

        if ($parsed === false || $parsed->format('Y-m-d') !== $from) {
            return $reject('effective_from harus tanggal YYYY-MM-DD yang valid');
        }

        if (! isset($teams[$from])) {
            $teams[$from] = [];

            foreach ($this->directory->snapshot($from)->all() as $node) {
                $teams[$from][$node->code] = $node->id;
            }
        }

        $unitId = $teams[$from][$team] ?? null;

        if ($unitId === null) {
            return $reject("team {$team} tidak aktif pada {$from}");
        }

        $existing = Employee::query()->where('employee_number', $number)->first();

        if ($existing !== null) {
            $homeUnitId = $this->directory->homeUnitId($existing->id, $this->today());
            $same = $existing->full_name === ($cells['full_name'] ?? '') && $homeUnitId !== null && $homeUnitId === $this->unitIdOn($this->today(), $team, $teams);

            return $same
                ? ['result' => 'unchanged', 'message' => "Baris {$line}: {$number} sudah ada dan sama.", 'team' => $team]
                : ['result' => 'conflicts', 'message' => "Baris {$line}: {$number} sudah ada dengan nama atau home team berbeda; tidak ditimpa.", 'team' => $team];
        }

        try {
            $this->employees->register($actor, $number, $cells['full_name'] ?? '', $unitId, $from, ($cells['position_title'] ?? '') === '' ? null : $cells['position_title'], 'Migrasi data pegawai');
        } catch (BusinessRuleViolation $violation) {
            return $reject($violation->safeMessage());
        }

        return ['result' => 'created', 'message' => "Baris {$line}: {$number} dibuat.", 'team' => $team];
    }

    /**
     * Control totals: every source row ends up created, unchanged, in
     * conflict, or rejected; and the employees present per team match the
     * rows that were taken.
     *
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     * @param  array{created: list<string>, unchanged: list<string>, conflicts: list<string>, rejected: list<string>}  $result
     * @param  array<string, int>  $perTeam
     * @return array<string, mixed>
     */
    private function reconcile(array $rows, array $result, array $perTeam): array
    {
        $accounted = count($result['created']) + count($result['unchanged']) + count($result['conflicts']) + count($result['rejected']);
        ksort($perTeam);

        return [
            'source_rows' => count($rows),
            'created' => count($result['created']),
            'unchanged' => count($result['unchanged']),
            'conflicts' => count($result['conflicts']),
            'rejected' => count($result['rejected']),
            'accounted' => $accounted,
            'balanced' => $accounted === count($rows),
            'per_team' => $perTeam,
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $teams
     */
    private function unitIdOn(string $date, string $team, array &$teams): ?int
    {
        if (! isset($teams[$date])) {
            $teams[$date] = [];

            foreach ($this->directory->snapshot($date)->all() as $node) {
                $teams[$date][$node->code] = $node->id;
            }
        }

        return $teams[$date][$team] ?? null;
    }

    private function today(): string
    {
        return $this->time->businessDate(CarbonImmutable::now());
    }
}
