<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\AssignmentType;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\EmployeeAssignment;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Employee master data and effective-dated team assignments (PRD §4.1).
 *
 * History rules:
 * - a transfer closes the open home assignment and opens a new one;
 * - transfers, temporary assignments, and early ends take effect today or
 *   later; only a newly registered employee may start in the past;
 * - an assignment whose end date has already passed is immutable;
 * - every change bumps the employee lock_version so concurrent editors of
 *   the same employee cannot interleave.
 */
final readonly class EmployeeService
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $lock,
        private OrganizationDirectory $directory,
        private BusinessTime $time,
    ) {}

    public function register(
        Actor $actor,
        string $employeeNumber,
        string $fullName,
        ?int $homeUnitId,
        ?string $effectiveFrom,
        ?string $positionTitle,
        ?string $reason,
    ): Employee {
        $employeeNumber = self::normalizeEmployeeNumber($employeeNumber);
        $fullName = self::requireText($fullName, 'full_name', 'Nama pegawai wajib diisi.', 160);
        $positionTitle = self::optionalText($positionTitle, 120);
        $reason = self::optionalText($reason);

        if ($homeUnitId !== null) {
            if ($effectiveFrom === null) {
                throw new BusinessRuleViolation('effective_from_required', 'Tanggal mulai home team wajib diisi.');
            }

            self::requirePeriod($effectiveFrom);
        }

        return $this->transactions->run(function () use ($actor, $employeeNumber, $fullName, $homeUnitId, $effectiveFrom, $positionTitle, $reason): Employee {
            if (Employee::query()->where('employee_number', $employeeNumber)->exists()) {
                throw new BusinessRuleViolation('employee_number_taken', 'Nomor induk pegawai sudah terdaftar.');
            }

            try {
                $employee = Employee::query()->create([
                    'employee_number' => $employeeNumber,
                    'full_name' => $fullName,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleViolation('employee_number_taken', 'Nomor induk pegawai sudah terdaftar.');
            }

            $this->audit->record(new AuditEntry(
                action: 'employee.registered',
                entityType: 'employee',
                entityId: $employee->public_id,
                before: null,
                after: ['employee_number' => $employeeNumber, 'full_name' => $fullName],
                reason: $reason,
                context: $actor->audit,
            ));

            if ($homeUnitId !== null) {
                $this->openAssignment($actor, $employee, AssignmentType::Home, $homeUnitId, $effectiveFrom, null, $positionTitle, $reason);
            }

            return $employee;
        });
    }

    public function rename(Actor $actor, int $employeeId, int $expectedVersion, string $fullName, string $reason): Employee
    {
        $fullName = self::requireText($fullName, 'full_name', 'Nama pegawai wajib diisi.', 160);
        $reason = self::requireText($reason, 'reason', 'Alasan perubahan wajib diisi.');

        return $this->transactions->run(function () use ($actor, $employeeId, $expectedVersion, $fullName, $reason): Employee {
            $employee = Employee::query()->findOrFail($employeeId);
            $before = $employee->full_name;

            if ($before === $fullName) {
                throw new BusinessRuleViolation('no_change', 'Nama pegawai tidak berubah.');
            }

            $employee = $this->lock->update($employee, $expectedVersion, ['full_name' => $fullName]);

            $this->audit->record(new AuditEntry(
                action: 'employee.renamed',
                entityType: 'employee',
                entityId: $employee->public_id,
                before: ['full_name' => $before],
                after: ['full_name' => $fullName],
                reason: $reason,
                context: $actor->audit,
            ));

            return $employee;
        });
    }

    public function transferHome(
        Actor $actor,
        int $employeeId,
        int $expectedVersion,
        int $unitId,
        string $effectiveFrom,
        ?string $positionTitle,
        string $reason,
    ): EmployeeAssignment {
        $reason = self::requireText($reason, 'reason', 'Alasan perpindahan wajib diisi.');
        $positionTitle = self::optionalText($positionTitle, 120);
        self::requirePeriod($effectiveFrom);
        self::requireNotBefore($effectiveFrom, $this->today());

        return $this->transactions->run(function () use ($actor, $employeeId, $expectedVersion, $unitId, $effectiveFrom, $positionTitle, $reason): EmployeeAssignment {
            $employee = $this->lock->update(Employee::query()->findOrFail($employeeId), $expectedVersion, []);

            $current = EmployeeAssignment::query()
                ->where('employee_id', $employee->id)
                ->where('assignment_type', AssignmentType::Home->value)
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                if ($current->effective_from >= $effectiveFrom) {
                    throw new BusinessRuleViolation('assignment_overlap', 'Tanggal pindah harus setelah tanggal mulai home team terakhir.');
                }

                if ($current->effective_until === null || $current->effective_until > $effectiveFrom) {
                    if ($current->organization_unit_id === $unitId && $current->position_title === $positionTitle) {
                        throw new BusinessRuleViolation('no_change', 'Pegawai sudah berada di team dan jabatan tersebut.');
                    }

                    $this->closeAssignment($actor, $current, $effectiveFrom, $reason);
                }
            }

            return $this->openAssignment($actor, $employee, AssignmentType::Home, $unitId, $effectiveFrom, null, $positionTitle, $reason);
        });
    }

    public function addTemporary(
        Actor $actor,
        int $employeeId,
        int $expectedVersion,
        int $unitId,
        string $effectiveFrom,
        string $effectiveUntil,
        ?string $positionTitle,
        string $reason,
    ): EmployeeAssignment {
        $reason = self::requireText($reason, 'reason', 'Alasan penugasan sementara wajib diisi.');
        $positionTitle = self::optionalText($positionTitle, 120);
        self::requirePeriod($effectiveFrom, $effectiveUntil);
        self::requireNotBefore($effectiveFrom, $this->today());

        return $this->transactions->run(function () use ($actor, $employeeId, $expectedVersion, $unitId, $effectiveFrom, $effectiveUntil, $positionTitle, $reason): EmployeeAssignment {
            $employee = $this->lock->update(Employee::query()->findOrFail($employeeId), $expectedVersion, []);

            $overlaps = EmployeeAssignment::query()
                ->where('employee_id', $employee->id)
                ->where('assignment_type', AssignmentType::Temporary->value)
                ->where('organization_unit_id', $unitId)
                ->where('effective_from', '<', $effectiveUntil)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $effectiveFrom))
                ->exists();

            if ($overlaps) {
                throw new BusinessRuleViolation('assignment_overlap', 'Sudah ada penugasan sementara ke team yang sama pada periode tersebut.');
            }

            return $this->openAssignment($actor, $employee, AssignmentType::Temporary, $unitId, $effectiveFrom, $effectiveUntil, $positionTitle, $reason);
        });
    }

    public function endAssignment(Actor $actor, int $assignmentId, int $expectedVersion, string $effectiveUntil, string $reason): EmployeeAssignment
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pengakhiran wajib diisi.');
        self::requirePeriod($effectiveUntil);
        self::requireNotBefore($effectiveUntil, $this->today());

        return $this->transactions->run(function () use ($actor, $assignmentId, $expectedVersion, $effectiveUntil, $reason): EmployeeAssignment {
            $assignment = $this->lock->update(EmployeeAssignment::query()->findOrFail($assignmentId), $expectedVersion, []);

            if ($assignment->effective_until !== null && $effectiveUntil >= $assignment->effective_until) {
                throw new BusinessRuleViolation('not_shortening', 'Tanggal akhir baru harus sebelum tanggal akhir saat ini.');
            }

            $employee = Employee::query()->findOrFail($assignment->employee_id);
            $this->lock->update($employee, $employee->lock_version, []);

            return $this->closeAssignment($actor, $assignment, $effectiveUntil, $reason);
        });
    }

    private function openAssignment(
        Actor $actor,
        Employee $employee,
        AssignmentType $type,
        int $unitId,
        string $effectiveFrom,
        ?string $effectiveUntil,
        ?string $positionTitle,
        ?string $reason,
    ): EmployeeAssignment {
        $unit = $this->directory->snapshot($effectiveFrom)->unit($unitId);
        $unitRecord = OrganizationUnit::query()->find($unitId);

        if ($unit === null || $unit->type !== UnitType::Team || $unitRecord === null) {
            throw new BusinessRuleViolation('invalid_unit', 'Penugasan hanya dapat dibuat ke team yang aktif pada tanggal mulai.');
        }

        if ($unitRecord->effective_until !== null && ($effectiveUntil === null || $effectiveUntil > $unitRecord->effective_until)) {
            throw new BusinessRuleViolation('invalid_unit', 'Team ditutup sebelum penugasan berakhir.');
        }

        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'organization_unit_id' => $unitId,
            'assignment_type' => $type,
            'position_title' => $positionTitle,
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'reason' => $reason,
            'created_by_user_id' => $actor->userId,
        ]);

        $this->audit->record(new AuditEntry(
            action: 'employee.assignment_opened',
            entityType: 'employee',
            entityId: $employee->public_id,
            before: null,
            after: [
                'assignment_id' => $assignment->public_id,
                'assignment_type' => $type->value,
                'unit_code' => $unit->code,
                'position_title' => $positionTitle,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
            ],
            reason: $reason,
            context: $actor->audit,
        ));

        return $assignment;
    }

    private function closeAssignment(Actor $actor, EmployeeAssignment $assignment, string $effectiveUntil, string $reason): EmployeeAssignment
    {
        if ($assignment->effective_until !== null && $assignment->effective_until <= $this->today()) {
            throw new BusinessRuleViolation('history_locked', 'Penugasan yang sudah berakhir tidak dapat diubah.');
        }

        if ($effectiveUntil <= $assignment->effective_from) {
            throw new BusinessRuleViolation('invalid_period', 'Tanggal akhir harus setelah tanggal mulai penugasan.');
        }

        $before = $assignment->effective_until;

        $assignment = $this->lock->update($assignment, $assignment->lock_version, [
            'effective_until' => $effectiveUntil,
            'end_reason' => $reason,
            'ended_by_user_id' => $actor->userId,
        ]);

        $this->audit->record(new AuditEntry(
            action: 'employee.assignment_ended',
            entityType: 'employee',
            entityId: $assignment->employee->public_id,
            before: ['assignment_id' => $assignment->public_id, 'effective_until' => $before],
            after: ['assignment_id' => $assignment->public_id, 'effective_until' => $effectiveUntil],
            reason: $reason,
            context: $actor->audit,
        ));

        return $assignment;
    }

    private function today(): string
    {
        return $this->time->businessDate(CarbonImmutable::now());
    }

    private static function normalizeEmployeeNumber(string $value): string
    {
        $value = strtoupper(trim($value));

        if (preg_match('/^[A-Z0-9][A-Z0-9.\/-]{0,39}$/', $value) !== 1) {
            throw new BusinessRuleViolation('invalid_employee_number', 'Nomor induk pegawai hanya boleh berisi huruf, angka, titik, garis miring, dan tanda hubung.');
        }

        return $value;
    }
}
