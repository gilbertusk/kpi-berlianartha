<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Account lifecycle managed by System Admin. Accounts are provisioned without
 * a known password: the user sets it through a one-time reset link, so no
 * administrator ever learns or transmits a credential.
 */
final readonly class AccountService
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $lock,
        private AccessControl $access,
        private BusinessTime $time,
    ) {}

    /**
     * Creates the account, then sends the password setup link after commit.
     * A failed or throttled send leaves the account usable: the administrator
     * can resend the link from the account list.
     */
    public function provision(Actor $actor, string $name, string $email, ?string $employeeNumber): ProvisionedAccount
    {
        $name = self::requireText($name, 'name', 'Nama akun wajib diisi.', 255);
        $email = self::normalizeEmail($email);
        $employeeNumber = self::optionalText($employeeNumber, 40);

        $user = $this->transactions->run(function () use ($actor, $name, $email, $employeeNumber): User {
            if (User::query()->where('email', $email)->exists()) {
                throw new BusinessRuleViolation('email_taken', 'Email sudah digunakan oleh akun lain.');
            }

            $user = new User;

            try {
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    // Unusable random secret until the user completes the reset link.
                    'password' => Str::password(64),
                    'status' => AccountStatus::Active,
                    'provisioned_by_user_id' => $actor->userId,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleViolation('email_taken', 'Email sudah digunakan oleh akun lain.');
            }

            $employee = $employeeNumber === null ? null : $this->linkableEmployee($employeeNumber);

            if ($employee !== null) {
                $this->lock->update($employee, $employee->lock_version, ['user_id' => $user->id]);
            }

            $this->audit->record(new AuditEntry(
                action: 'account.provisioned',
                entityType: 'user',
                entityId: $user->public_id,
                before: null,
                after: [
                    'name' => $name,
                    'email' => $email,
                    'employee_id' => $employee?->public_id,
                    'status' => AccountStatus::Active->value,
                ],
                reason: null,
                context: $actor->audit,
            ));

            return $user;
        });

        return new ProvisionedAccount($user, $this->sendResetLink($actor, $user));
    }

    public function deactivate(Actor $actor, int $userId, int $expectedVersion, string $reason): User
    {
        $reason = self::requireText($reason, 'reason', 'Alasan penonaktifan wajib diisi.');

        if ($actor->userId === $userId) {
            throw new BusinessRuleViolation('self_account_change', 'Anda tidak dapat menonaktifkan akun sendiri.');
        }

        $user = $this->transactions->run(function () use ($actor, $userId, $expectedVersion, $reason): User {
            $user = User::query()->findOrFail($userId);

            if (! $user->isActive()) {
                throw new BusinessRuleViolation('account_inactive', 'Akun sudah nonaktif.');
            }

            $this->assertAnotherSystemAdminRemains($user);

            $user = $this->lock->update($user, $expectedVersion, [
                'status' => AccountStatus::Deactivated->value,
                'deactivated_at' => CarbonImmutable::now(),
                'deactivation_reason' => $reason,
                'remember_token' => Str::random(60),
            ]);

            $terminatedSessions = $this->terminateSessions($user);

            $this->audit->record(new AuditEntry(
                action: 'account.deactivated',
                entityType: 'user',
                entityId: $user->public_id,
                before: ['status' => AccountStatus::Active->value],
                after: ['status' => AccountStatus::Deactivated->value, 'terminated_sessions' => $terminatedSessions],
                reason: $reason,
                context: $actor->audit,
            ));

            return $user;
        });

        $this->access->flush();

        return $user;
    }

    public function reactivate(Actor $actor, int $userId, int $expectedVersion, string $reason): User
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pengaktifan kembali wajib diisi.');

        if ($actor->userId === $userId) {
            throw new BusinessRuleViolation('self_account_change', 'Anda tidak dapat mengaktifkan akun sendiri.');
        }

        $user = $this->transactions->run(function () use ($actor, $userId, $expectedVersion, $reason): User {
            $user = User::query()->findOrFail($userId);

            if ($user->isActive()) {
                throw new BusinessRuleViolation('account_active', 'Akun sudah aktif.');
            }

            $user = $this->lock->update($user, $expectedVersion, [
                'status' => AccountStatus::Active->value,
                'deactivated_at' => null,
                'deactivation_reason' => null,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'account.reactivated',
                entityType: 'user',
                entityId: $user->public_id,
                before: ['status' => AccountStatus::Deactivated->value],
                after: ['status' => AccountStatus::Active->value],
                reason: $reason,
                context: $actor->audit,
            ));

            return $user;
        });

        $this->access->flush();

        return $user;
    }

    public function sendPasswordReset(Actor $actor, int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        if (! $user->isActive()) {
            throw new BusinessRuleViolation('account_inactive', 'Tautan reset tidak dikirim untuk akun nonaktif.');
        }

        if (! $this->sendResetLink($actor, $user)) {
            throw new BusinessRuleViolation('reset_link_not_sent', 'Tautan reset belum dapat dikirim. Tunggu sebentar lalu coba kembali.');
        }
    }

    public function linkEmployee(Actor $actor, int $userId, int $expectedVersion, ?string $employeeNumber, string $reason): User
    {
        $reason = self::requireText($reason, 'reason', 'Alasan perubahan wajib diisi.');
        $employeeNumber = self::optionalText($employeeNumber, 40);

        $user = $this->transactions->run(function () use ($actor, $userId, $expectedVersion, $employeeNumber, $reason): User {
            $user = $this->lock->update(User::query()->findOrFail($userId), $expectedVersion, []);
            $current = Employee::query()->where('user_id', $user->id)->first();
            $next = $employeeNumber === null ? null : Employee::query()->where('employee_number', strtoupper($employeeNumber))->first();

            if ($employeeNumber !== null && $next === null) {
                throw new BusinessRuleViolation('employee_not_found', 'Nomor induk pegawai tidak ditemukan.');
            }

            if ($current?->id === $next?->id) {
                throw new BusinessRuleViolation('no_change', 'Akun sudah terhubung dengan data pegawai tersebut.');
            }

            if ($next !== null && $next->user_id !== null) {
                throw new BusinessRuleViolation('employee_linked', 'Data pegawai sudah terhubung dengan akun lain.');
            }

            if ($current !== null) {
                $this->lock->update($current, $current->lock_version, ['user_id' => null]);
            }

            if ($next !== null) {
                $this->lock->update($next, $next->lock_version, ['user_id' => $user->id]);
            }

            $this->audit->record(new AuditEntry(
                action: 'account.employee_link_changed',
                entityType: 'user',
                entityId: $user->public_id,
                before: ['employee_id' => $current?->public_id],
                after: ['employee_id' => $next?->public_id],
                reason: $reason,
                context: $actor->audit,
            ));

            return $user;
        });

        $this->access->flush();

        return $user;
    }

    private function linkableEmployee(string $employeeNumber): Employee
    {
        $employee = Employee::query()->where('employee_number', strtoupper($employeeNumber))->first();

        if ($employee === null) {
            throw new BusinessRuleViolation('employee_not_found', 'Nomor induk pegawai tidak ditemukan.');
        }

        if ($employee->user_id !== null) {
            throw new BusinessRuleViolation('employee_linked', 'Data pegawai sudah terhubung dengan akun lain.');
        }

        return $employee;
    }

    /**
     * Database sessions can be revoked immediately. Other drivers rely on
     * the EnsureAccountIsActive middleware rejecting the next request.
     */
    private function terminateSessions(User $user): int
    {
        if (Config::string('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(Config::string('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * Sends the reset/setup link and audits only a link that was actually
     * handed to the mailer. Throttling or a transport failure returns false.
     */
    private function sendResetLink(Actor $actor, User $user): bool
    {
        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return false;
        }

        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(
            action: 'account.password_reset_link_sent',
            entityType: 'user',
            entityId: $user->public_id,
            before: null,
            after: null,
            reason: null,
            context: $actor->audit,
        )));

        return true;
    }

    /**
     * Deactivating the last active System Admin would lock everyone out of
     * account and role administration. Admin rows are locked so two admins
     * cannot deactivate each other concurrently.
     */
    private function assertAnotherSystemAdminRemains(User $target): void
    {
        $now = CarbonImmutable::now();

        $adminIds = RoleAssignment::query()
            ->where('role', Role::SystemAdmin->value)
            ->activeAt($this->time->businessDate($now), $now)
            ->pluck('user_id')
            ->all();

        if (! in_array($target->id, $adminIds, true)) {
            return;
        }

        $remaining = User::query()
            ->whereIn('id', $adminIds)
            ->where('status', AccountStatus::Active->value)
            ->lockForUpdate()
            ->pluck('id')
            ->reject(fn (mixed $id): bool => (int) $id === $target->id)
            ->count();

        if ($remaining === 0) {
            throw new BusinessRuleViolation('last_system_admin', 'System Admin aktif terakhir tidak dapat dinonaktifkan.');
        }
    }

    private static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new BusinessRuleViolation('invalid_email', 'Format email tidak valid.');
        }

        return $email;
    }
}
