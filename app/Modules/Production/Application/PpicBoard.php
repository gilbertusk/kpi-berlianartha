<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use App\Models\User;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\PpicStatus;
use App\Modules\Production\Infrastructure\Persistence\Models\PpicStatusChange;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * The PPIC board (PRD §10.1). Work orders, their schedule, and their
 * material readiness come from the latest committed work order batch of the
 * PPIC team: the KPI system never changes the ERP schedule. The board status
 * is the KPI system's own, append-only history of moves with time, actor,
 * work order, and the reason for a late or backward move.
 */
final readonly class PpicBoard
{
    use GuardsInput;

    public const UNSET = 'unset';

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private ProductionAccess $access,
        private BusinessTime $time,
    ) {}

    public function currentBatch(): ?ProductionBatch
    {
        $unitId = $this->access->ppicUnitId();

        return $unitId === null ? null : ProductionBatch::query()->current()
            ->where('organization_unit_id', $unitId)
            ->where('kind', BatchKind::WorkOrders->value)
            ->orderByDesc('coverage_date')->orderByDesc('version')->orderByDesc('id')
            ->first();
    }

    /**
     * Work orders of the current batch with their board status and flags.
     *
     * @param  array<string, string>  $filters  customer, order, work_order, need_from, need_to, machine, status
     * @return list<array<string, mixed>>
     */
    public function rows(User $user, array $filters = []): array
    {
        if (! $this->access->canViewBoard($user)) {
            throw new AuthorizationException;
        }

        $batch = $this->currentBatch();

        if ($batch === null) {
            return [];
        }

        $filter = static fn (string $key): string => trim($filters[$key] ?? '');
        $records = ProductionRecord::query()->where('production_batch_id', $batch->id)->where('record_type', ProductionRecord::WORK_ORDER)
            ->when($filter('customer') !== '', fn ($query) => $query->where('customer_code', $filter('customer')))
            ->when($filter('work_order') !== '', fn ($query) => $query->where('work_order', $filter('work_order')))
            ->when($filter('machine') !== '', fn ($query) => $query->where('machine_code', $filter('machine')))
            ->when($filter('need_from') !== '', fn ($query) => $query->where('due_date', '>=', $filter('need_from')))
            ->when($filter('need_to') !== '', fn ($query) => $query->where('due_date', '<=', $filter('need_to')))
            ->orderBy('due_date')->orderBy('work_order')
            ->get()
            ->filter(fn (ProductionRecord $record): bool => $filter('order') === '' || $record->detail('order_number') === $filter('order'))
            ->values();

        $numbers = array_values($records->map(fn (ProductionRecord $record): string => (string) $record->work_order)->all());
        $statuses = $this->statuses($numbers);
        $previous = $this->previousSchedules($batch, $numbers);
        $output = ProductionQuery::outputByWorkOrder($numbers);
        $today = $this->time->businessDate(CarbonImmutable::now());
        $rows = [];

        foreach ($records as $record) {
            $number = (string) $record->work_order;
            $last = $statuses[$number] ?? null;
            $status = $last?->to_status;

            if ($filter('status') !== '' && ($status === null ? self::UNSET : $status->value) !== $filter('status')) {
                continue;
            }

            $before = $previous[$number] ?? null;
            $progress = [];
            $otherUnits = [];

            foreach ($output[$number] ?? [] as $team => $units) {
                foreach ($units as $unit => $quantity) {
                    if ($unit === (string) $record->unit) {
                        $progress[$team] = $quantity;
                    } else {
                        $otherUnits[] = "{$team} {$unit}";
                    }
                }
            }

            ksort($progress);

            $rows[] = [
                'record' => $record,
                'status' => $status,
                'last_change' => $last,
                'overdue' => $record->due_date !== null && $record->due_date < $today && $status !== PpicStatus::Completed,
                'material_not_ready' => $record->detail('material_status') !== 'ready',
                'emergency' => $record->detail('priority') === 'emergency',
                'schedule_changed' => $before !== null && $before !== $this->schedule($record),
                'previous_schedule' => $before,
                'output_by_team' => $progress,
                'output_in_other_units' => $otherUnits,
            ];
        }

        return $rows;
    }

    /**
     * Moves a work order on the board. The caller states the status it saw,
     * so a move made meanwhile by someone else is never overwritten.
     */
    public function move(Actor $actor, User $user, string $workOrder, ?PpicStatus $expected, PpicStatus $to, ?string $reason = null): PpicStatusChange
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the acting account must be the same.');
        }

        if (! $this->access->canManageBoard($user)) {
            throw new AuthorizationException;
        }

        $reason = self::optionalText($reason, 1000);
        $batch = $this->currentBatch() ?? throw new BusinessRuleViolation('no_work_orders', 'Belum ada data work order dari sistem sumber.');
        $record = ProductionRecord::query()->where('production_batch_id', $batch->id)->where('record_type', ProductionRecord::WORK_ORDER)->where('work_order', $workOrder)->first()
            ?? throw new BusinessRuleViolation('unknown_work_order', 'Work order tidak ada pada data sumber terbaru.');

        try {
            return $this->transactions->run(function () use ($actor, $user, $batch, $record, $expected, $to, $reason): PpicStatusChange {
                $unitId = $batch->organization_unit_id;
                $last = PpicStatusChange::query()->where('organization_unit_id', $unitId)->where('work_order', $record->work_order)->orderByDesc('sequence')->first();
                $current = $last?->to_status;

                if ($current !== $expected) {
                    throw new BusinessRuleViolation('stale_status', 'Status work order sudah diubah oleh pengguna lain. Muat ulang board.');
                }

                if ($current === $to) {
                    throw new BusinessRuleViolation('same_status', 'Work order sudah berada pada status tersebut.');
                }

                $today = $this->time->businessDate(CarbonImmutable::now());
                $overdue = $record->due_date !== null && $record->due_date < $today;

                if ($overdue && $reason === null) {
                    throw new BusinessRuleViolation('late_reason_required', "Work order melewati tanggal kebutuhan {$record->due_date}; alasan keterlambatan wajib diisi.");
                }

                if ($current !== null && $to->isBefore($current) && $reason === null) {
                    throw new BusinessRuleViolation('backward_reason_required', 'Memindahkan work order ke status sebelumnya membutuhkan alasan.');
                }

                $change = PpicStatusChange::query()->create([
                    'organization_unit_id' => $unitId,
                    'work_order' => $record->work_order,
                    'sequence' => ($last->sequence ?? 0) + 1,
                    'from_status' => $current,
                    'to_status' => $to,
                    'need_date' => $record->due_date,
                    'was_overdue' => $overdue,
                    'reason' => $reason,
                    'production_batch_id' => $batch->id,
                    'changed_by_user_id' => $user->id,
                    'changed_at' => CarbonImmutable::now(),
                ]);

                $this->audit->record(new AuditEntry(
                    action: 'ppic_status.changed',
                    entityType: 'ppic_work_order',
                    entityId: (string) $record->work_order,
                    before: ['status' => $current?->value],
                    after: ['status' => $to->value, 'sequence' => $change->sequence, 'overdue' => $overdue],
                    reason: $reason,
                    context: $actor->audit,
                ));

                return $change;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('stale_status', 'Status work order sudah diubah oleh pengguna lain. Muat ulang board.');
        }
    }

    /**
     * @return list<PpicStatusChange>
     */
    public function history(User $user, string $workOrder): array
    {
        if (! $this->access->canViewBoard($user)) {
            throw new AuthorizationException;
        }

        return array_values(PpicStatusChange::query()->with('changedBy')->where('organization_unit_id', (int) $this->access->ppicUnitId())->where('work_order', $workOrder)->orderBy('sequence')->get()->all());
    }

    /**
     * @param  list<string>  $numbers
     * @return array<string, PpicStatusChange>
     */
    private function statuses(array $numbers): array
    {
        $latest = [];

        foreach (array_chunk($numbers, 500) as $chunk) {
            foreach (PpicStatusChange::query()->with('changedBy')->where('organization_unit_id', (int) $this->access->ppicUnitId())->whereIn('work_order', $chunk)->orderBy('sequence')->get() as $change) {
                $latest[$change->work_order] = $change;
            }
        }

        return $latest;
    }

    /**
     * The schedule each work order had in the previous date's batch, to show
     * schedule changes the source made.
     *
     * @param  list<string>  $numbers
     * @return array<string, string>
     */
    private function previousSchedules(ProductionBatch $batch, array $numbers): array
    {
        $previous = ProductionBatch::query()->current()
            ->where('organization_unit_id', $batch->organization_unit_id)
            ->where('kind', BatchKind::WorkOrders->value)
            ->where('coverage_date', '<', $batch->coverage_date)
            ->orderByDesc('coverage_date')->orderByDesc('version')
            ->first();

        if ($previous === null) {
            return [];
        }

        $schedules = [];

        foreach (array_chunk($numbers, 500) as $chunk) {
            foreach (ProductionRecord::query()->where('production_batch_id', $previous->id)->where('record_type', ProductionRecord::WORK_ORDER)->whereIn('work_order', $chunk)->get() as $record) {
                $schedules[(string) $record->work_order] = $this->schedule($record);
            }
        }

        return $schedules;
    }

    private function schedule(ProductionRecord $record): string
    {
        return sprintf('mulai %s · selesai %s · kebutuhan %s · mesin %s', $record->detail('planned_start') ?? '—', $record->detail('planned_end') ?? '—', $record->due_date ?? '—', $record->machine_code ?? '—');
    }
}
