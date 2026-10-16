<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\Quality\Application\NcrQuery;
use App\Modules\Quality\Application\QualityAccess;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Shared\Application\Time\BusinessTime;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * NCRs in scope with stage, open stage PIC and due date, overdue days,
 * traceability, linked KPI version, and repeat links. Evidence and stage
 * content stay on the NCR page.
 */
final class QualityNcrReport implements ReportDefinition
{
    public function __construct(
        private readonly ReportSupport $support,
        private readonly QualityAccess $access,
        private readonly NcrQuery $query,
        private readonly BusinessTime $time,
    ) {}

    public function key(): string
    {
        return 'quality_ncrs';
    }

    public function title(): string
    {
        return 'NCR dan CAPA';
    }

    public function description(): string
    {
        return 'NCR per tahap dengan PIC, tenggat, hari lewat tenggat, sumber, work order/produk/lot, KPI terkait (versi), dan tautan repeat.';
    }

    public function columns(): array
    {
        return ['NCR', 'Tahap', 'Siklus', 'Team', 'Sumber', 'Referensi sumber', 'Kategori', 'Severity', 'Kuantitas', 'Satuan', 'Work order', 'Produk', 'Lot', 'Ditemukan', 'PIC tahap terbuka', 'Tenggat', 'Lewat tenggat (hari)', 'Pemilik', 'KPI terkait', 'Ulangan dari', 'Closed'];
    }

    public function filters(): array
    {
        return ['unit', 'status'];
    }

    public function statuses(): array
    {
        $options = [];

        foreach (NcrStage::cases() as $stage) {
            // Report filter codes are lower case; NCR stages are not.
            $options[strtolower($stage->value)] = $stage->label();
        }

        return $options;
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function available(User $user): bool
    {
        return $this->support->mayUseReports($user) && $this->access->canView($user);
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        if (! $this->available($user)) {
            throw new AuthorizationException;
        }

        $scope = $this->access->readScope($user);

        return new ReportContext($user, $filters, null, $this->support->units($filters, $scope), $this->support->describe($filters, $scope));
    }

    public function rows(ReportContext $context): iterable
    {
        $filters = ['stage' => $context->filters->status === null ? '' : strtoupper($context->filters->status)];

        foreach ($this->query->rows($context->user, $filters, 20000, $context->unitIds) as $row) {
            $ncr = $row['ncr'];
            $open = $row['open'];

            yield [
                $ncr->number, $ncr->stage->label(), (string) $ncr->cycle, $ncr->responsibleUnit->code, $ncr->source_type->label(), $ncr->source_reference,
                $ncr->category_code, $ncr->severity, $ncr->quantity, $ncr->unit, $ncr->work_order, $ncr->product_code, $ncr->lot,
                $this->local($ncr->detected_at), $open?->pic->name, $open?->due_date, $open === null ? null : (string) $row['overdue_days'], $ncr->owner->name,
                $ncr->kpiVersion === null ? null : "{$ncr->kpiVersion->name} v{$ncr->kpiVersion->version_number}", $ncr->repeatOf?->number, $this->local($ncr->closed_at),
            ];
        }
    }

    private function local(?\DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : $this->time->toBusiness($moment)->format('Y-m-d H:i');
    }
}
