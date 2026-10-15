<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\Production\Application\PpicBoard;
use App\Modules\Production\Application\ProductionAccess;
use App\Modules\Production\Domain\PpicStatus;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Shared\Application\Time\BusinessTime;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The PPIC board as a report: work orders of the latest source data with
 * board status, flags, and output progress in the planned unit.
 */
final class PpicWorkOrderReport implements ReportDefinition
{
    public function __construct(
        private readonly ReportSupport $support,
        private readonly ProductionAccess $access,
        private readonly PpicBoard $board,
        private readonly BusinessTime $time,
    ) {}

    public function key(): string
    {
        return 'ppic_work_orders';
    }

    public function title(): string
    {
        return 'Work order dan status board PPIC';
    }

    public function description(): string
    {
        return 'Work order dari data sumber terbaru dengan jadwal, kesiapan material, penanda overdue/emergency/perubahan jadwal, progres output, dan status board.';
    }

    public function columns(): array
    {
        return ['Work order', 'Order', 'Customer', 'Produk', 'Mesin', 'Rencana mulai', 'Rencana selesai', 'Tanggal kebutuhan', 'Material', 'Design', 'Prioritas', 'Status board', 'Overdue', 'Jadwal berubah', 'Output good per team', 'Rencana', 'Satuan', 'Perubahan status terakhir'];
    }

    public function filters(): array
    {
        return ['status'];
    }

    public function statuses(): array
    {
        $options = [PpicBoard::UNSET => 'Belum ditetapkan'];

        foreach (PpicStatus::cases() as $status) {
            // Report filter codes are lower case; board statuses are not.
            $options[strtolower($status->value)] = $status->label();
        }

        return $options;
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function available(User $user): bool
    {
        return $this->support->mayUseReports($user) && $this->access->canViewBoard($user);
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        if (! $this->available($user)) {
            throw new AuthorizationException;
        }

        $batch = $this->board->currentBatch();

        return new ReportContext($user, $filters, null, null, $batch === null ? 'Board PPIC (belum ada data)' : "Board PPIC — work order per {$batch->coverage_date} v{$batch->version}");
    }

    public function rows(ReportContext $context): iterable
    {
        foreach ($this->board->rows($context->user, ['status' => $context->filters->status === null || $context->filters->status === PpicBoard::UNSET ? (string) $context->filters->status : strtoupper($context->filters->status)]) as $row) {
            $record = $row['record'];
            $last = $row['last_change'];

            yield [
                $record->work_order, $record->detail('order_number'), $record->customer_code, $record->product_code, $record->machine_code,
                $record->detail('planned_start'), $record->detail('planned_end'), $record->due_date, $record->detail('material_status'), $record->detail('design_status'),
                $record->detail('priority'), $row['status']?->label() ?? 'Belum ditetapkan', $row['overdue'] ? 'ya' : 'tidak', $row['schedule_changed'] ? 'ya' : 'tidak',
                $this->outputByTeam($row['output_by_team']), $record->quantity, $record->unit, $last === null ? null : $this->time->toBusiness($last->changed_at)->format('Y-m-d H:i'),
            ];
        }
    }

    /**
     * @param  array<array-key, string>  $output
     */
    private function outputByTeam(array $output): string
    {
        $parts = [];

        foreach ($output as $team => $quantity) {
            $parts[] = "{$team} {$quantity}";
        }

        return implode('; ', $parts);
    }
}
